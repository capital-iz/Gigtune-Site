<?php
/**
 * Template Name: Artist Directory (Gemini UI + GigTune Core Data)
 * Description: /artists page UI matches Gemini DiscoveryPage. Data comes from GigTune Core logic-only API when available.
 *
 * MODES:
 * - Force placeholders: ?placeholders=1
 * - Force live data (even if empty): ?live=1
 */

get_header();
get_template_part('template-parts/navbar');

/**
 * Inline SVG icon helper (no external icon libs required)
 */
if (!defined('GIGTUNE_CANON_ARTISTS_HELPERS_LOADED')) {
define('GIGTUNE_CANON_ARTISTS_HELPERS_LOADED', true);

function gigtune_svg_icon($name, $class = 'w-5 h-5') {
  $class_attr = esc_attr($class);

  switch ($name) {
    case 'search':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M11 20a9 9 0 1 1 0-18 9 9 0 0 1 0 18Z" stroke="currentColor" stroke-width="2"/>
        <path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>';

    case 'music':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M9 18V6l12-2v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M9 18a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z" stroke="currentColor" stroke-width="2"/>
        <path d="M21 16a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z" stroke="currentColor" stroke-width="2"/>
      </svg>';

    case 'star':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12 2.5l2.95 6 6.62.96-4.79 4.67 1.13 6.6L12 17.9l-5.91 3.11 1.13-6.6L2.43 9.46l6.62-.96L12 2.5Z"/>
      </svg>';

    case 'checkcircle':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M22 4 12 14.01l-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>';

    default:
      return '';
  }
}

/**
 * Safe helpers for live artist payload mapping
 */
function gigtune_ui_artist_rating($artist) {
  if (isset($artist['ratings']['performance_avg']) && is_numeric($artist['ratings']['performance_avg'])) {
    return (float) $artist['ratings']['performance_avg'];
  }
  if (isset($artist['ratings']['reliability_avg']) && is_numeric($artist['ratings']['reliability_avg'])) {
    return (float) $artist['ratings']['reliability_avg'];
  }
  return null;
}

function gigtune_ui_artist_role_label($artist) {
  if (!empty($artist['terms']['performer_type']) && is_array($artist['terms']['performer_type'])) {
    $t = $artist['terms']['performer_type'][0];
    if (!empty($t['name'])) return (string) $t['name'];
  }
  return 'Artist';
}

function gigtune_ui_artist_location_label($artist) {
  if (!empty($artist['availability']['base_area'])) return (string) $artist['availability']['base_area'];
  return '';
}

function gigtune_ui_artist_price_range($artist) {
  if (!empty($artist['pricing']) && is_array($artist['pricing'])) {
    $min = isset($artist['pricing']['min']) ? (float) $artist['pricing']['min'] : null;
    $max = isset($artist['pricing']['max']) ? (float) $artist['pricing']['max'] : null;

    if ($min || $max) {
      $fmt = function($n) { return 'R' . number_format((float)$n, 0, '.', ','); };
      if ($min && $max) return $fmt($min) . ' - ' . $fmt($max);
      if ($min) return 'From ' . $fmt($min);
      if ($max) return 'Up to ' . $fmt($max);
    }
  }
  return '';
}

function gigtune_ui_artist_tags($artist) {
  $tag_tax = ['instrument_category', 'keyboard_parts', 'vocal_type', 'vocal_role'];
  $out = [];
  if (!empty($artist['terms']) && is_array($artist['terms'])) {
    foreach ($tag_tax as $tx) {
      if (!empty($artist['terms'][$tx]) && is_array($artist['terms'][$tx])) {
        foreach ($artist['terms'][$tx] as $t) {
          if (!empty($t['name'])) $out[] = (string) $t['name'];
          if (count($out) >= 3) break 2;
        }
      }
    }
  }
  return $out;
}

function gigtune_ui_artist_name_for_sort($artist) {
  if (!empty($artist['title']) && is_string($artist['title'])) {
    return (string) $artist['title'];
  }
  if (!empty($artist['name']) && is_string($artist['name'])) {
    return (string) $artist['name'];
  }
  return '';
}

function gigtune_ui_artist_available_now($artist) {
  $raw = null;
  if (isset($artist['availability']['available_now'])) {
    $raw = $artist['availability']['available_now'];
  } elseif (isset($artist['availability']['is_available'])) {
    $raw = $artist['availability']['is_available'];
  } elseif (isset($artist['available_now'])) {
    $raw = $artist['available_now'];
  } elseif (isset($artist['meta']['gigtune_artist_available_now'])) {
    $raw = $artist['meta']['gigtune_artist_available_now'];
  }

  if (is_bool($raw)) {
    return $raw;
  }
  if (is_numeric($raw)) {
    return ((int) $raw) === 1;
  }
  if (is_string($raw)) {
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on', 'available'], true);
  }
  return false;
}

function gigtune_ui_artist_price_value($artist) {
  if (isset($artist['pricing']) && is_array($artist['pricing'])) {
    $min = isset($artist['pricing']['min']) && is_numeric($artist['pricing']['min']) ? (float) $artist['pricing']['min'] : null;
    $max = isset($artist['pricing']['max']) && is_numeric($artist['pricing']['max']) ? (float) $artist['pricing']['max'] : null;
    if ($min !== null && $min > 0) {
      return $min;
    }
    if ($max !== null && $max > 0) {
      return $max;
    }
  }

  if (isset($artist['priceRange']) && is_string($artist['priceRange'])) {
    if (preg_match('/([\d,]+)/', $artist['priceRange'], $m)) {
      return (float) str_replace(',', '', $m[1]);
    }
  }

  return 0.0;
}

function gigtune_ui_sort_artists(&$artists, $sort_by, $sort_dir) {
  if (!is_array($artists) || count($artists) < 2) {
    return;
  }

  $sort_by = sanitize_key((string) $sort_by);
  $sort_dir = strtolower((string) $sort_dir) === 'desc' ? 'desc' : 'asc';
  if ($sort_by === '') {
    return;
  }

  usort($artists, function($a, $b) use ($sort_by, $sort_dir) {
    $direction = ($sort_dir === 'desc') ? -1 : 1;
    $cmp = 0;

    if ($sort_by === 'name') {
      $cmp = strcasecmp(gigtune_ui_artist_name_for_sort($a), gigtune_ui_artist_name_for_sort($b));
      return $cmp * $direction;
    }

    if ($sort_by === 'availability') {
      $a_val = gigtune_ui_artist_available_now($a) ? 1 : 0;
      $b_val = gigtune_ui_artist_available_now($b) ? 1 : 0;
      if ($a_val !== $b_val) {
        return ($a_val < $b_val ? -1 : 1) * $direction;
      }
      return strcasecmp(gigtune_ui_artist_name_for_sort($a), gigtune_ui_artist_name_for_sort($b));
    }

    if ($sort_by === 'rating') {
      $a_rating = gigtune_ui_artist_rating($a);
      $b_rating = gigtune_ui_artist_rating($b);
      $a_val = ($a_rating !== null) ? (float) $a_rating : -1.0;
      $b_val = ($b_rating !== null) ? (float) $b_rating : -1.0;
      if ($a_val !== $b_val) {
        return ($a_val < $b_val ? -1 : 1) * $direction;
      }
      return strcasecmp(gigtune_ui_artist_name_for_sort($a), gigtune_ui_artist_name_for_sort($b));
    }

    if ($sort_by === 'price') {
      $a_val = gigtune_ui_artist_price_value($a);
      $b_val = gigtune_ui_artist_price_value($b);
      if ($a_val !== $b_val) {
        return ($a_val < $b_val ? -1 : 1) * $direction;
      }
      return strcasecmp(gigtune_ui_artist_name_for_sort($a), gigtune_ui_artist_name_for_sort($b));
    }

    return 0;
  });
}
}

/**
 * Gemini placeholders (UI parity)
 */
$MOCK_ARTISTS = [
  [
    'id' => 'a1',
    'name' => 'The Midnight Jazz Trio',
    'role' => 'Jazz Band',
    'location' => 'Cape Town',
    'rating' => 4.9,
    'priceRange' => 'R5,000 - R12,000',
    'verified' => true,
    'tags' => ['Jazz', 'Swing', 'Lounge'],
  ],
  [
    'id' => 'a2',
    'name' => 'DJ Pulse',
    'role' => 'DJ',
    'location' => 'Johannesburg',
    'rating' => 4.8,
    'priceRange' => 'R3,000 - R8,000',
    'verified' => true,
    'tags' => ['House', 'Top 40', 'Corporate'],
  ],
  [
    'id' => 'a3',
    'name' => 'Sarah Jenkins',
    'role' => 'Acoustic Soloist',
    'location' => 'Durban',
    'rating' => 5.0,
    'priceRange' => 'R2,500 - R6,000',
    'verified' => true,
    'tags' => ['Pop', 'Indie', 'Folk'],
  ],
];

$force_placeholders = (isset($_GET['placeholders']) && $_GET['placeholders'] === '1');
$force_live = (isset($_GET['live']) && $_GET['live'] === '1');

$has_live_api = function_exists('gigtune_core_get_artists') && function_exists('gigtune_core_get_filter_options');

if (!function_exists('gigtune_canon_city_canonical')) {
  function gigtune_canon_city_canonical($city_label) {
    $city_label = is_string($city_label) ? $city_label : '';
    $city_label = trim($city_label);
    if ($city_label === '') {
      return '';
    }
    $city_label = preg_replace('/\s*\([^)]*\)\s*/', ' ', $city_label);
    $city_label = preg_replace('/\s+/', ' ', (string) $city_label);
    return trim((string) $city_label);
  }
}

// Filters from GET
$search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$location = isset($_GET['location']) ? sanitize_text_field(wp_unslash($_GET['location'])) : '';

// New twofold location filter (Province + City)
// - province: optional (filters by province string when no city is selected)
// - city: preferred (filters base_area by selected city)
$province = isset($_GET['province']) ? sanitize_text_field(wp_unslash($_GET['province'])) : '';
$city = isset($_GET['city']) ? gigtune_canon_city_canonical(sanitize_text_field(wp_unslash($_GET['city']))) : '';

// Date filter (weekday) stored as ['mon','tue',...]
$date_filter = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : ''; // values: '', 'weekend', 'mon'..'sun'

// Budget filter uses "min-max" or "15000+" string values (same pattern used in legacy code)
$budget = isset($_GET['budget']) ? sanitize_text_field(wp_unslash($_GET['budget'])) : ''; // e.g. "0-5000", "5000-15000", "15000+"
$sort_choice = isset($_GET['sort_choice']) ? sanitize_key(wp_unslash($_GET['sort_choice'])) : '';
$sort_by = isset($_GET['sort_by']) ? sanitize_key(wp_unslash($_GET['sort_by'])) : '';
$sort_dir = isset($_GET['sort_dir']) ? strtolower(sanitize_key(wp_unslash($_GET['sort_dir']))) : '';

$sort_choice_map = [
  'name_asc' => ['sort_by' => 'name', 'sort_dir' => 'asc'],
  'name_desc' => ['sort_by' => 'name', 'sort_dir' => 'desc'],
  'availability_desc' => ['sort_by' => 'availability', 'sort_dir' => 'desc'],
  'rating_desc' => ['sort_by' => 'rating', 'sort_dir' => 'desc'],
  'rating_asc' => ['sort_by' => 'rating', 'sort_dir' => 'asc'],
  'price_asc' => ['sort_by' => 'price', 'sort_dir' => 'asc'],
  'price_desc' => ['sort_by' => 'price', 'sort_dir' => 'desc'],
];

if ($sort_choice !== '' && isset($sort_choice_map[$sort_choice])) {
  $sort_by = $sort_choice_map[$sort_choice]['sort_by'];
  $sort_dir = $sort_choice_map[$sort_choice]['sort_dir'];
}

if (!in_array($sort_by, ['name', 'availability', 'rating', 'price'], true)) {
  $sort_by = '';
}
if (!in_array($sort_dir, ['asc', 'desc'], true)) {
  $sort_dir = '';
}

if ($sort_choice === '' && $sort_by !== '' && $sort_dir !== '') {
  foreach ($sort_choice_map as $key => $mapped) {
    if ($mapped['sort_by'] === $sort_by && $mapped['sort_dir'] === $sort_dir) {
      $sort_choice = $key;
      break;
    }
  }
}

// Taxonomy filter (we expose performer_type as a simple dropdown, and keep support for the rest via GET param)
$taxonomies = ['performer_type', 'instrument_category', 'keyboard_parts', 'vocal_type', 'vocal_role'];
$tax_query = [];
foreach ($taxonomies as $tx) {
  if (!empty($_GET[$tx])) {
    $slug = sanitize_text_field(wp_unslash($_GET[$tx]));
    $tax_query[] = [
      'taxonomy' => $tx,
      'field' => 'slug',
      'terms' => [$slug],
    ];
  }
}
if (!empty($tax_query)) {
  $tax_query['relation'] = 'AND';
}

// Parse budget string into min/max
$budget_min = 0;
$budget_max = 0;
if ($budget !== '') {
  if (strpos($budget, '+') !== false) {
    $budget_min = absint(str_replace('+', '', $budget));
    $budget_max = 0;
  } elseif (strpos($budget, '-') !== false) {
    $parts = explode('-', $budget);
    $budget_min = absint($parts[0] ?? 0);
    $budget_max = absint($parts[1] ?? 0);
  }
}

$meta_query = [];

// Location filter uses correct meta key from gigtune-core: gigtune_artist_base_area
// Prefer city; if only province is selected, filter by province string (best-effort).
$location_effective = $city !== '' ? $city : $location;
if (!empty($location_effective)) {
  $meta_query[] = [
    'key' => 'gigtune_artist_base_area',
    'value' => $location_effective,
    'compare' => 'LIKE',
  ];
} elseif (!empty($province)) {
  $meta_query[] = [
    'key' => 'gigtune_artist_base_area',
    'value' => $province,
    'compare' => 'LIKE',
  ];
}

// Date filter: weekday LIKE search inside stored array (serialized)
if ($date_filter !== '') {
  if ($date_filter === 'weekend') {
    $meta_query[] = [
      'relation' => 'OR',
      [
        'key' => 'gigtune_artist_availability_days',
        'value' => '"sat"',
        'compare' => 'LIKE',
      ],
      [
        'key' => 'gigtune_artist_availability_days',
        'value' => '"sun"',
        'compare' => 'LIKE',
      ],
    ];
  } else {
    $allowed_days = ['mon','tue','wed','thu','fri','sat','sun'];
    if (in_array($date_filter, $allowed_days, true)) {
      $meta_query[] = [
        'key' => 'gigtune_artist_availability_days',
        'value' => '"' . $date_filter . '"',
        'compare' => 'LIKE',
      ];
    }
  }
}

// Budget filter against artist min/max meta (stored as integers)
if ($budget_min > 0 && $budget_max > 0) {
  $meta_query[] = [
    'relation' => 'AND',
    [
      'key' => 'gigtune_artist_price_max',
      'value' => $budget_min,
      'type' => 'NUMERIC',
      'compare' => '>=',
    ],
    [
      'key' => 'gigtune_artist_price_min',
      'value' => $budget_max,
      'type' => 'NUMERIC',
      'compare' => '<=',
    ],
  ];
} elseif ($budget_min > 0 && $budget_max <= 0) {
  $meta_query[] = [
    'key' => 'gigtune_artist_price_max',
    'value' => $budget_min,
    'type' => 'NUMERIC',
    'compare' => '>=',
  ];
}

// Pull taxonomy options for UI dropdown (performer_type)
$filter_options = [];
if ($has_live_api) {
  $filter_options = gigtune_core_get_filter_options();
}

$live_payload = null;
$live_items = [];
$use_live = false;

if ($has_live_api && !$force_placeholders) {
  $args = [
    'per_page' => 60,
    'paged' => 1,
  ];
  if ($search !== '') $args['search'] = $search;
  if (!empty($tax_query)) $args['tax_query'] = $tax_query;
  if (!empty($meta_query)) $args['meta_query'] = $meta_query;

  $live_payload = gigtune_core_get_artists($args);
  if (is_array($live_payload) && !empty($live_payload['items'])) {
    $live_items = $live_payload['items'];
  }

  $use_live = $force_live || (!empty($live_items));
}

if ($sort_by !== '' && $sort_dir !== '') {
  if (!empty($live_items)) {
    gigtune_ui_sort_artists($live_items, $sort_by, $sort_dir);
  }
  if (!empty($MOCK_ARTISTS)) {
    gigtune_ui_sort_artists($MOCK_ARTISTS, $sort_by, $sort_dir);
  }
}

?>

  <div id="gt-artists-page" class="pt-12 pb-24 w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 animate-fade-in">
  <div class="mb-8 md:mb-12">
    <h2 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white mb-3 md:mb-4">Artist Directory</h2>
    <p class="text-slate-400 text-base md:text-lg max-w-2xl mx-auto">
      Find the perfect fit for your event with our verified roster.
    </p>
  </div>

  <!-- Filters Toolbar (Gemini structure/classes) -->
  <form id="gt-artist-filter-form" method="get" class="flex flex-col md:flex-row gap-4 mb-8 bg-slate-800 p-4 rounded-xl border border-slate-700">
    <div class="flex-1 relative">
      <span class="absolute left-3 top-3 text-slate-500 w-5 h-5">
        <?php echo gigtune_svg_icon('search', 'w-5 h-5'); ?>
      </span>
      <input
        type="text"
        name="q"
        value="<?php echo esc_attr($search); ?>"
        placeholder="Search by genre, name, or instrument..."
        class="w-full bg-slate-900 border border-slate-700 rounded-lg py-2.5 pl-10 pr-4 text-white focus:outline-none focus:border-purple-500"
      />
      <?php if ($use_live) : ?>
        <input type="hidden" name="live" value="1" />
      <?php endif; ?>
      <input type="hidden" name="sort_by" id="gt-sort-by" value="<?php echo esc_attr($sort_by); ?>" />
      <input type="hidden" name="sort_dir" id="gt-sort-dir" value="<?php echo esc_attr($sort_dir); ?>" />
    </div>

    <div class="flex gap-2 overflow-x-auto pb-2 md:pb-0 hide-scrollbar">
      <!-- Date (NOW WORKS) -->
      <select name="date" class="bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-4 py-2.5 flex-shrink-0">
        <?php
          $date_options = [
            '' => 'Date',
            'weekend' => 'This Weekend',
            'mon' => 'Mon',
            'tue' => 'Tue',
            'wed' => 'Wed',
            'thu' => 'Thu',
            'fri' => 'Fri',
            'sat' => 'Sat',
            'sun' => 'Sun',
          ];
          foreach ($date_options as $val => $label) {
            $sel = ($date_filter === $val) ? 'selected' : '';
            echo '<option value="'.esc_attr($val).'" '.$sel.'>'.esc_html($label).'</option>';
          }
        ?>
      </select>

      <!-- Budget (NOW WORKS) -->
      <select name="budget" class="bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-4 py-2.5 flex-shrink-0">
        <?php
          $budget_options = [
            '' => 'Budget',
            '0-5000' => 'R0 - R5k',
            '5000-15000' => 'R5k - R15k',
            '15000+' => 'R15k+',
          ];
          foreach ($budget_options as $val => $label) {
            $sel = ($budget === $val) ? 'selected' : '';
            echo '<option value="'.esc_attr($val).'" '.$sel.'>'.esc_html($label).'</option>';
          }
        ?>
      </select>

      <!-- Location (Province + City) -->
      <select name="province" id="gt-province" class="bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-4 py-2.5 flex-shrink-0">
        <option value="">Province</option>
        <?php
          // South Africa provinces (official)
          $provinces = [
            'Eastern Cape',
            'Free State',
            'Gauteng',
            'KwaZulu-Natal',
            'Limpopo',
            'Mpumalanga',
            'North West',
            'Northern Cape',
            'Western Cape',
          ];
          foreach ($provinces as $p) {
            $sel = ($province === $p) ? 'selected' : '';
            echo '<option value="'.esc_attr($p).'" '.$sel.'>'.esc_html($p).'</option>';
          }
        ?>
      </select>

      <select name="city" id="gt-city" data-selected="<?php echo esc_attr($city); ?>" class="bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-4 py-2.5 flex-shrink-0">
        <option value="">Select province first</option>
        <!-- Options populated by JS based on Province; server keeps selection via data-selected -->
      </select>

      <!-- Back-compat: keep accepting legacy ?location=City links; do not render an extra UI control -->
      <?php if ($location !== '' && $city === '') : ?>
        <input type="hidden" name="location" value="<?php echo esc_attr($location); ?>" />
      <?php endif; ?>

      <!-- Performer Type taxonomy filter (NOW WORKS) -->
      <select name="performer_type" class="bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-4 py-2.5 flex-shrink-0">
        <option value="">Performer</option>
        <?php
          $perf_terms = isset($filter_options['performer_type']) && is_array($filter_options['performer_type']) ? $filter_options['performer_type'] : [];
          $current_perf = isset($_GET['performer_type']) ? sanitize_text_field(wp_unslash($_GET['performer_type'])) : '';
          foreach ($perf_terms as $t) {
            $slug = isset($t['slug']) ? (string)$t['slug'] : '';
            $name = isset($t['name']) ? (string)$t['name'] : $slug;
            if ($slug === '') continue;
            $sel = ($current_perf === $slug) ? 'selected' : '';
            echo '<option value="'.esc_attr($slug).'" '.$sel.'>'.esc_html($name).'</option>';
          }
        ?>
      </select>

      <select name="sort_choice" id="gt-sort-choice" class="bg-slate-900 border border-slate-700 text-slate-300 rounded-lg px-4 py-2.5 flex-shrink-0">
        <option value="">Sort by</option>
        <option value="name_asc" <?php selected($sort_choice, 'name_asc'); ?>>Name (A-Z)</option>
        <option value="name_desc" <?php selected($sort_choice, 'name_desc'); ?>>Name (Z-A)</option>
        <option value="availability_desc" <?php selected($sort_choice, 'availability_desc'); ?>>Availability (Available first)</option>
        <option value="rating_desc" <?php selected($sort_choice, 'rating_desc'); ?>>Rating (Highest)</option>
        <option value="rating_asc" <?php selected($sort_choice, 'rating_asc'); ?>>Rating (Lowest)</option>
        <option value="price_asc" <?php selected($sort_choice, 'price_asc'); ?>>Price (Lowest)</option>
        <option value="price_desc" <?php selected($sort_choice, 'price_desc'); ?>>Price (Highest)</option>
      </select>
    </div>

    <div class="md:self-start">
      <button type="submit" class="inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 transition whitespace-nowrap">
        Apply
      </button>
    </div>
  </form>

  <script>
    (function(){
      function gtCityInit() {
      // Province -> at least 3 major cities each (South Africa)
      var PROVINCE_CITIES = {
        'Eastern Cape': ['Gqeberha (Port Elizabeth)', 'East London', 'Mthatha'],
        'Free State': ['Bloemfontein', 'Welkom', 'Bethlehem'],
        'Gauteng': ['Johannesburg', 'Pretoria', 'Soweto'],
        'KwaZulu-Natal': ['Durban', 'Pietermaritzburg', 'Richards Bay'],
        'Limpopo': ['Polokwane', 'Tzaneen', 'Thohoyandou'],
        'Mpumalanga': ['Nelspruit (Mbombela)', 'Witbank (eMalahleni)', 'Secunda'],
        'North West': ['Rustenburg', 'Mahikeng', 'Klerksdorp'],
        'Northern Cape': ['Kimberley', 'Upington', 'Springbok'],
        'Western Cape': ['Cape Town', 'Stellenbosch', 'George']
      };

      var provinceSel = document.getElementById('gt-province');
      var citySel = document.getElementById('gt-city');
      var form = document.getElementById('gt-artist-filter-form');
      var sortChoice = document.getElementById('gt-sort-choice');
      var sortByInput = document.getElementById('gt-sort-by');
      var sortDirInput = document.getElementById('gt-sort-dir');
      if (!provinceSel || !citySel) {
        if (!window.__GT_CITY_FILTER_INIT_FAIL_LOGGED) {
          window.__GT_CITY_FILTER_INIT_FAIL_LOGGED = true;
          try { console.warn('GT_CITY_FILTER_INIT_FAIL'); } catch (e) {}
        }
        return;
      }

      function canonicalCityValue(label) {
        return String(label || '').replace(/\s*\([^)]*\)\s*/g, ' ').replace(/\s+/g, ' ').trim();
      }

      function setCityOptions(province, selectedCity){
        var cities = PROVINCE_CITIES[province] || [];
        var selectedCanonical = canonicalCityValue(selectedCity);

        // reset
        citySel.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = province && cities.length > 0 ? 'City' : 'Select province first';
        citySel.appendChild(opt0);

        if (!province || cities.length === 0) {
          citySel.disabled = false;
          citySel.value = '';
          return;
        }

        citySel.disabled = false;
        cities.forEach(function(city){
          var canonicalValue = canonicalCityValue(city);
          var opt = document.createElement('option');
          opt.value = canonicalValue;
          opt.textContent = city;
          if (selectedCanonical && selectedCanonical === canonicalValue) opt.selected = true;
          citySel.appendChild(opt);
        });
      }

      var initialProvince = provinceSel.value || '';
      var initialCity = canonicalCityValue(citySel.getAttribute('data-selected') || '');
      setCityOptions(initialProvince, initialCity);

      provinceSel.addEventListener('change', function(){
        // When province changes, clear city selection
        setCityOptions(provinceSel.value || '', '');
      });

      function applySortChoiceFields() {
        if (!sortChoice || !sortByInput || !sortDirInput) return;
        var map = {
          name_asc: { by: 'name', dir: 'asc' },
          name_desc: { by: 'name', dir: 'desc' },
          availability_desc: { by: 'availability', dir: 'desc' },
          rating_desc: { by: 'rating', dir: 'desc' },
          rating_asc: { by: 'rating', dir: 'asc' },
          price_asc: { by: 'price', dir: 'asc' },
          price_desc: { by: 'price', dir: 'desc' }
        };
        var choice = sortChoice.value || '';
        if (map[choice]) {
          sortByInput.value = map[choice].by;
          sortDirInput.value = map[choice].dir;
        } else {
          sortByInput.value = '';
          sortDirInput.value = '';
        }
      }

      if (form) {
        form.addEventListener('submit', applySortChoiceFields);
      }
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', gtCityInit);
      } else {
        gtCityInit();
      }
    })();
  </script>

  <!-- Grid -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6" id="gt-artist-grid">
    <?php if ($use_live) : ?>
      <?php if (!empty($live_items)) : ?>
        <?php foreach ($live_items as $artist) : ?>
          <?php
            $title = !empty($artist['title']) ? (string) $artist['title'] : 'Artist';
            $role = gigtune_ui_artist_role_label($artist);
            $loc = gigtune_ui_artist_location_label($artist);
            $rating = gigtune_ui_artist_rating($artist);
            $rating_display = ($rating !== null) ? rtrim(rtrim(number_format($rating, 1, '.', ''), '0'), '.') : '—';
            $price = gigtune_ui_artist_price_range($artist);
            $tags = gigtune_ui_artist_tags($artist);
            $photo_url = !empty($artist['photo']['url']) ? (string) $artist['photo']['url'] : '';

            // IMPORTANT: artist_profile CPT is not public, so use the /artist-profile page with artist_id param.
            $profile_url = site_url('/artist-profile/');
            if (!empty($artist['id'])) {
              $profile_url = add_query_arg('artist_id', (int) $artist['id'], $profile_url);
            }
          ?>
          <div class="bg-slate-800 rounded-xl overflow-hidden border border-slate-700 hover:border-purple-500/50 transition-all group gt-artist-card">
            <div class="h-48 bg-slate-700 relative">
              <?php if ($photo_url !== '') : ?>
                <img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($title); ?>" class="w-full h-full object-cover" loading="lazy" />
              <?php endif; ?>
              <div class="w-full h-full bg-gradient-to-t from-slate-900 to-transparent absolute bottom-0 z-10"></div>
              <?php if ($photo_url === '') : ?>
                <div class="absolute inset-0 flex items-center justify-center text-slate-500">
                  <?php echo gigtune_svg_icon('music', 'w-12 h-12 opacity-20'); ?>
                </div>
              <?php endif; ?>

              <div class="absolute top-4 right-4 z-20">
                <span class="bg-slate-900/80 backdrop-blur text-white text-xs font-bold px-2 py-1 rounded flex items-center gap-1">
                  <span class="w-3 h-3 text-yellow-400">
                    <?php echo gigtune_svg_icon('star', 'w-3 h-3 text-yellow-400 fill-yellow-400'); ?>
                  </span>
                  <?php echo esc_html($rating_display); ?>
                </span>
              </div>
            </div>

            <div class="p-6">
              <div class="flex justify-between items-start mb-2">
                <div>
                  <h3 class="text-xl font-bold text-white group-hover:text-purple-400 transition-colors">
                    <?php echo esc_html($title); ?>
                  </h3>
                  <p class="text-slate-400 text-sm">
                    <?php echo esc_html($role); ?>
                    <?php if ($loc !== '') : ?>
                      • <?php echo esc_html($loc); ?>
                    <?php endif; ?>
                  </p>
                </div>
                <span class="text-blue-400 w-5 h-5 flex-shrink-0">
                  <?php echo gigtune_svg_icon('checkcircle', 'w-5 h-5 text-blue-400'); ?>
                </span>
              </div>

              <?php if (!empty($tags)) : ?>
                <div class="flex flex-wrap gap-2 mb-4">
                  <?php foreach ($tags as $tag) : ?>
                    <span class="text-xs bg-slate-900 text-slate-400 px-2 py-1 rounded border border-slate-700">
                      <?php echo esc_html($tag); ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if ($price !== '') : ?>
                <div class="flex items-center justify-between text-sm text-slate-300 mb-6">
                  <span class="flex items-center gap-1">
                    <?php echo esc_html($price); ?>
                  </span>
                </div>
              <?php else : ?>
                <div class="mb-6"></div>
              <?php endif; ?>

              <a
                href="<?php echo esc_url($profile_url); ?>"
                class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20 w-full"
              >
                View Profile
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else : ?>
        <div class="col-span-full text-center py-12 text-slate-500">
          <div class="mx-auto mb-4 opacity-20">
            <?php echo gigtune_svg_icon('search', 'w-12 h-12'); ?>
          </div>
          <p>No artists found.</p>
        </div>
      <?php endif; ?>

    <?php else : ?>
      <?php foreach ($MOCK_ARTISTS as $artist) : ?>
        <div class="bg-slate-800 rounded-xl overflow-hidden border border-slate-700 hover:border-purple-500/50 transition-all group gt-artist-card">
          <div class="h-48 bg-slate-700 relative">
            <div class="w-full h-full bg-gradient-to-t from-slate-900 to-transparent absolute bottom-0 z-10"></div>
            <div class="absolute inset-0 flex items-center justify-center text-slate-500">
              <?php echo gigtune_svg_icon('music', 'w-12 h-12 opacity-20'); ?>
            </div>

            <div class="absolute top-4 right-4 z-20">
              <span class="bg-slate-900/80 backdrop-blur text-white text-xs font-bold px-2 py-1 rounded flex items-center gap-1">
                <span class="w-3 h-3 text-yellow-400">
                  <?php echo gigtune_svg_icon('star', 'w-3 h-3 text-yellow-400 fill-yellow-400'); ?>
                </span>
                <?php echo esc_html($artist['rating']); ?>
              </span>
            </div>
          </div>

          <div class="p-6">
            <div class="flex justify-between items-start mb-2">
              <div>
                <h3 class="text-xl font-bold text-white group-hover:text-purple-400 transition-colors">
                  <?php echo esc_html($artist['name']); ?>
                </h3>
                <p class="text-slate-400 text-sm">
                  <?php echo esc_html($artist['role']); ?> • <?php echo esc_html($artist['location']); ?>
                </p>
              </div>
              <?php if (!empty($artist['verified'])) : ?>
                <span class="text-blue-400 w-5 h-5 flex-shrink-0">
                  <?php echo gigtune_svg_icon('checkcircle', 'w-5 h-5 text-blue-400'); ?>
                </span>
              <?php endif; ?>
            </div>

            <div class="flex flex-wrap gap-2 mb-4">
              <?php foreach ($artist['tags'] as $tag) : ?>
                <span class="text-xs bg-slate-900 text-slate-400 px-2 py-1 rounded border border-slate-700">
                  <?php echo esc_html($tag); ?>
                </span>
              <?php endforeach; ?>
            </div>

            <div class="flex items-center justify-between text-sm text-slate-300 mb-6">
              <span class="flex items-center gap-1">
                <?php echo esc_html($artist['priceRange']); ?>
              </span>
            </div>

            <a
              href="<?php echo esc_url(site_url('/artist-profile/')); ?>"
              class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20 w-full"
            >
              View Profile
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="mt-8 text-center">
    <button id="gtLoadMoreArtists" type="button" class="inline-flex items-center justify-center rounded-lg px-6 py-3 text-sm font-semibold text-white bg-slate-800 hover:bg-slate-700 border border-slate-700 transition">
      Load more
    </button>
  </div>

  <script>
  (function() {
    var grid = document.getElementById('gt-artist-grid');
    var button = document.getElementById('gtLoadMoreArtists');
    if (!grid || !button) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.gt-artist-card'));
    var batchSize = 6;
    var visibleCount = 0;

    function updateVisibility() {
      cards.forEach(function(card, index) {
        card.style.display = index < visibleCount ? '' : 'none';
      });
      if (visibleCount >= cards.length) {
        button.style.display = 'none';
      } else {
        button.style.display = '';
      }
    }

    function showMore() {
      visibleCount = Math.min(visibleCount + batchSize, cards.length);
      updateVisibility();
    }

    if (cards.length <= batchSize) {
      button.style.display = 'none';
      return;
    }

    showMore();
    button.addEventListener('click', showMore);
  })();
  </script>

  <!-- Helpers: hide-scrollbar + fade -->
  <style>
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
  </style>
</div>

<?php
get_footer();
