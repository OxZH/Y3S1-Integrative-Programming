<?php
// Configuration, view and id helpers. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'] ?? [];

    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

// Escaping happens on output, not on input: the same stored value goes to HTML,
// to JSON and back to the database, and each needs different treatment.
// ENT_QUOTES matters, or a value inside a single quoted attribute can break out.
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $controller, string $action = 'index', array $params = []): string
{
    $query = array_filter(
        array_merge(['c' => $controller, 'a' => $action], $params),
        function ($v) { return $v !== null && $v !== ''; }
    );

    return 'index.php?' . http_build_query($query);
}

function money(float $amount): string
{
    return 'RM' . number_format($amount, 2);
}

function hhmm(string $time): string
{
    return substr($time, 0, 5);
}

// Re-fill a form field after a failed save.
function old(array $input, string $field, string $default = ''): string
{
    $value = $input[$field] ?? $default;

    return is_scalar($value) ? e((string) $value) : '';
}

// Every primary key is a UUID rather than an auto-increment integer, so ids
// cannot be guessed or walked. random_bytes is a CSPRNG; uniqid and mt_rand are not.
function uuid(): string
{
    $bytes = random_bytes(16);

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

// Star bar for a 0-5 rating, for anywhere that can hold markup. Null means
// nobody has rated it yet. The fill width is a class rather than an inline
// style, so every rule still lives in style.css.
function stars(?float $rating): string
{
    if ($rating === null) {
        return '<span class="stars-none">Not rated yet</span>';
    }

    // 4.5 becomes .stars-45, which style.css sets to 90% wide.
    $step = (int) (round($rating * 2) * 5);

    return '<span class="stars" title="' . e(number_format($rating, 1)) . ' out of 5">'
         . '<span class="stars-off">&#9733;&#9733;&#9733;&#9733;&#9733;</span>'
         . '<span class="stars-on stars-' . $step . '">&#9733;&#9733;&#9733;&#9733;&#9733;</span>'
         . '</span>'
         . '<span class="stars-value">' . e(number_format($rating, 1)) . '</span>';
}

// Stars as plain characters. A <select> option can only hold text, and the
// venue preview is filled in with textContent, so neither can take the markup
// version above.
function starsText(?float $rating): string
{
    if ($rating === null) {
        return 'not rated';
    }

    $full = (int) floor($rating);
    $half = ($rating - $full) >= 0.5 ? 1 : 0;

    // Written as escapes so this file stays plain ASCII: a filled star, a half
    // sign, and a hollow star.
    return str_repeat("\u{2605}", $full)
         . str_repeat("\u{00BD}", $half)
         . str_repeat("\u{2606}", 5 - $full - $half)
         . ' ' . number_format($rating, 1);
}

// A file under public/, addressed the way the browser needs it. The file's
// modified time is appended so an edited stylesheet or script is fetched again
// instead of a stale copy being served from the cache.
//
// The layout keeps its own copy of this for the two assets it loads. A view that
// needs one, such as the map and its Leaflet files, uses this.
function asset(string $relative): string
{
    $path = dirname(__DIR__) . '/public/' . ltrim($relative, '/');

    return rtrim((string) config('app.base_url'), '/') . '/' . ltrim($relative, '/')
         . '?v=' . (is_file($path) ? (string) filemtime($path) : '1');
}

// Turns a stored image value into something the browser can load. An uploaded
// photo is kept as a path relative to the app, so the app still works whatever
// folder it is served from; an outside link is kept whole.
function imageSrc(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }

    if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
        return $stored;
    }

    return rtrim((string) config('app.base_url'), '/') . '/' . ltrim($stored, '/');
}
