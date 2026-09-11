<?php
// Facility persistence and search. Author: Goh Jian Yu

namespace App\Model;

use App\Core\DataMapper;
use App\Core\Entity;
use App\Domain\SearchCriteria;
use App\FacilityStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final class FacilityMapper extends DataMapper
{
    private $accounts = null;

    protected function table(): string
    {
        return 'Facility';
    }

    protected function primaryKey(): string
    {
        return 'facilityId';
    }

    protected function columns(): array
    {
        return [
            'facilityId', 'ownerId', 'name', 'imageUrl', 'addressLine', 'postcode', 'city', 'state',
            'type', 'bookingFee', 'operationalHrsStart', 'operationalHrsEnd',
            'latitude', 'longitude', 'status', 'createdAt',
        ];
    }

    protected function toEntity(array $row): Entity
    {
        $facility = new Facility(
            (string) $row['facilityId'],
            (string) $row['name'],
            (string) $row['addressLine'],
            (string) $row['postcode'],
            (string) $row['city'],
            (string) $row['state'],
            (string) $row['type'],
            (float) $row['bookingFee'],
            (string) $row['operationalHrsStart'],
            (string) $row['operationalHrsEnd'],
            (float) $row['latitude'],
            (float) $row['longitude'],
            FacilityStatus::from((string) $row['status']),
            $row['imageUrl'] !== null ? (string) $row['imageUrl'] : null,
            new DateTimeImmutable((string) $row['createdAt'])
        );

        $ownerId = (string) $row['ownerId'];

        $facility->setLoader('owner', function () use ($ownerId) { return $this->accounts()->findAccount($ownerId); });

        return $facility;
    }

    protected function toRow(Entity $entity): array
    {
        if (!$entity instanceof Facility) {
            throw new InvalidArgumentException('FacilityMapper can only persist a Facility.');
        }

        $owner = $entity->getOwner();

        if ($owner === null) {
            throw new InvalidArgumentException('A facility must belong to an owner.');
        }

        return [
            'facilityId'          => $entity->getFacilityId(),
            'ownerId'             => $owner->getBaseUserId(),
            'name'                => $entity->getName(),
            'imageUrl'            => $entity->getImageUrl(),
            'addressLine'         => $entity->getAddressLine(),
            'postcode'            => $entity->getPostcode(),
            'city'                => $entity->getCity(),
            'state'               => $entity->getState(),
            'type'                => $entity->getType(),
            'bookingFee'          => number_format($entity->getBookingFee(), 2, '.', ''),
            'operationalHrsStart' => $entity->getOperationalHrsStart(),
            'operationalHrsEnd'   => $entity->getOperationalHrsEnd(),
            'latitude'            => $entity->getLatitude(),
            'longitude'           => $entity->getLongitude(),
            'status'              => $entity->getStatus()->value,
            'createdAt'           => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    public function findByOwner(string $ownerId): array
    {
        $facilities = $this->findBy(['ownerId' => $ownerId], 'createdAt', 'DESC');

        return $facilities;
    }

    // The only string built into this query is the ORDER BY expression, and it
    // comes from SearchCriteria::SORTS - a fixed array. A ?sort= value the user
    // invents is dropped before it gets here.
    public function search(SearchCriteria $criteria): array
    {
        $select = 'f.*';
        $where  = ['f.`status` = :status'];
        $params = [':status' => FacilityStatus::ACTIVE->value];

        if ($criteria->keyword !== null) {
            // Four placeholders for one value: with emulated prepares off, a
            // repeated placeholder name leaves the statement a parameter short.
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $criteria->keyword) . '%';

            // The postcode is searched too. It used to be part of addressLine,
            // so typing one still has to find the venue now that it lives in a
            // column of its own.
            $where[] = '(f.`name` LIKE :kw1 OR f.`addressLine` LIKE :kw2 '
                     . 'OR f.`city` LIKE :kw3 OR f.`postcode` LIKE :kw4)';
            $params[':kw1'] = $like;
            $params[':kw2'] = $like;
            $params[':kw3'] = $like;
            $params[':kw4'] = $like;
        }

        if ($criteria->city !== null) {
            $where[] = 'f.`city` = :city';
            $params[':city'] = $criteria->city;
        }

        if ($criteria->type !== null) {
            $where[] = 'f.`type` = :type';
            $params[':type'] = $criteria->type;
        }

        if ($criteria->maxFee !== null) {
            $where[] = 'f.`bookingFee` <= :maxFee';
            $params[':maxFee'] = $criteria->maxFee;
        }

        if ($criteria->hasOrigin()) {
            // Haversine in SQL so ORDER BY distance works and LIMIT returns the
            // genuinely nearest rows.
            $select .= ', (6371 * ACOS(LEAST(1.0,'
                     . ' COS(RADIANS(:lat1)) * COS(RADIANS(f.`latitude`))'
                     . ' * COS(RADIANS(f.`longitude`) - RADIANS(:lng1))'
                     . ' + SIN(RADIANS(:lat2)) * SIN(RADIANS(f.`latitude`))'
                     . '))) AS distanceKm';

            $params[':lat1'] = $criteria->latitude;
            $params[':lat2'] = $criteria->latitude;
            $params[':lng1'] = $criteria->longitude;

            if ($criteria->radiusKm !== null) {
                // Bounding box first: it is the part idx_Facility_geo can serve,
                // so Haversine only runs on rows that survive it.
                $latDelta = $criteria->radiusKm / 111.0;
                $lngDelta = $criteria->radiusKm / (111.0 * max(0.01, cos(deg2rad($criteria->latitude))));

                $where[] = 'f.`latitude` BETWEEN :latMin AND :latMax';
                $where[] = 'f.`longitude` BETWEEN :lngMin AND :lngMax';

                $params[':latMin'] = $criteria->latitude - $latDelta;
                $params[':latMax'] = $criteria->latitude + $latDelta;
                $params[':lngMin'] = $criteria->longitude - $lngDelta;
                $params[':lngMax'] = $criteria->longitude + $lngDelta;
            }
        }

        $sql = 'SELECT ' . $select . ' FROM `Facility` f WHERE ' . implode(' AND ', $where);

        if ($criteria->hasOrigin() && $criteria->radiusKm !== null) {
            $sql .= ' HAVING distanceKm <= :radius';
            $params[':radius'] = $criteria->radiusKm;
        }

        $sql .= sprintf(
            ' ORDER BY %s %s LIMIT %d',
            $criteria->sortExpression(),
            $this->direction($criteria->direction),
            $criteria->limit
        );

        $facilities = $this->hydrateAll($this->select($sql, $params));

        return $facilities;
    }

    // Rows that would be lost with the venue. Events use ON DELETE RESTRICT so
    // the database refuses anyway; reviews and ratings would cascade away
    // silently, which is exactly why they count as history worth keeping.
    public function countDependents(string $facilityId): int
    {
        $row = $this->selectOne(
            'SELECT (SELECT COUNT(*) FROM `Event` WHERE `facilityId` = :a)
                  + (SELECT COUNT(*) FROM `Review` WHERE `facilityId` = :b)
                  + (SELECT COUNT(*) FROM `FacilityRating` WHERE `facilityId` = :c) AS total',
            [':a' => $facilityId, ':b' => $facilityId, ':c' => $facilityId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function listCities(): array
    {
        return $this->distinct('city');
    }

    public function listTypes(): array
    {
        return $this->distinct('type');
    }

    private function distinct(string $column): array
    {
        $this->assertColumn($column);

        $rows = $this->select(
            sprintf('SELECT DISTINCT `%s` AS v FROM `Facility` WHERE `status` = :s ORDER BY `%s`', $column, $column),
            [':s' => FacilityStatus::ACTIVE->value]
        );

        return array_map(function ($r) { return $r['v']; }, $rows);
    }

    private function accounts(): AccountMapper
    {
        return $this->accounts ??= new AccountMapper($this->pdo);
    }
}
