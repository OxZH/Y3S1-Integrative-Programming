<?php
// Facility search filters. Author: Goh Jian Yu

namespace App\Domain;

// Holds the filters for one venue search, so the search method takes a single
// object instead of nine loose arguments.
class SearchCriteria
{
    // The only sort columns allowed. A column name cannot be sent to MySQL as a
    // bound parameter, so a ?sort= value the user invents is dropped here and
    // never reaches the query.
    const SORTS = [
        'name'     => 'f.`name`',
        'price'    => 'f.`bookingFee`',
        'city'     => 'f.`city`',
        'newest'   => 'f.`createdAt`',
        'distance' => 'distanceKm',
    ];

    public $keyword;
    public $city;
    public $type;
    public $maxFee;
    public $latitude;
    public $longitude;
    public $radiusKm;
    public $sortBy;
    public $direction;
    public $limit;

    public function __construct(
        $keyword = null,
        $city = null,
        $type = null,
        $maxFee = null,
        $latitude = null,
        $longitude = null,
        $radiusKm = null,
        $sortBy = 'name',
        $direction = 'ASC',
        $limit = 50
    ) {
        $this->keyword = $keyword;
        $this->city = $city;
        $this->type = $type;
        $this->maxFee = $maxFee;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->radiusKm = $radiusKm;
        $this->sortBy = $sortBy;
        $this->direction = $direction;
        $this->limit = $limit;
    }

    // Builds the object from $_GET, cleaning every value on the way in.
    public static function fromArray(array $input)
    {
        $sortBy = isset($input['sort']) && is_string($input['sort']) ? $input['sort'] : 'name';

        if (!array_key_exists($sortBy, SearchCriteria::SORTS)) {
            $sortBy = 'name';
        }

        $latitude  = SearchCriteria::toFloat(isset($input['lat']) ? $input['lat'] : null);
        $longitude = SearchCriteria::toFloat(isset($input['lng']) ? $input['lng'] : null);

        // sorting by distance means nothing without a point to measure from
        if ($sortBy === 'distance' && ($latitude === null || $longitude === null)) {
            $sortBy = 'name';
        }

        $direction = isset($input['dir']) ? strtoupper($input['dir']) : 'ASC';
        $limit     = isset($input['limit']) ? (int) $input['limit'] : 50;

        return new SearchCriteria(
            SearchCriteria::toText(isset($input['q']) ? $input['q'] : null),
            SearchCriteria::toText(isset($input['city']) ? $input['city'] : null),
            SearchCriteria::toText(isset($input['type']) ? $input['type'] : null),
            SearchCriteria::toFloat(isset($input['max_fee']) ? $input['max_fee'] : null),
            $latitude,
            $longitude,
            SearchCriteria::toFloat(isset($input['radius']) ? $input['radius'] : null),
            $sortBy,
            $direction === 'DESC' ? 'DESC' : 'ASC',
            min(100, max(1, $limit))
        );
    }

    public function hasOrigin()
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function sortExpression()
    {
        return isset(SearchCriteria::SORTS[$this->sortBy])
            ? SearchCriteria::SORTS[$this->sortBy]
            : SearchCriteria::SORTS['name'];
    }

    private static function toText($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function toFloat($value)
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
