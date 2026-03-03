<?php
/**
 * Template Name: Artist Profile (Gemini UI + GigTune Core Data)
 * Description: /artist-profile page. UI owned by theme (Gemini/Tailwind). Data comes from gigtune-core logic-only API.
 *
 * HOW THIS PAGE SELECTS AN ARTIST (Pick ONE):
 * 1) Query string:  /artist-profile/?artist_id=123
 * 2) Query string:  /artist-profile/?artist_slug=my-artist-slug
 * 3) Fallback: first published artist_profile post (if available)
 *
 * MODES:
 * - Force placeholders: ?placeholders=1
 */

get_header();
get_template_part('template-parts/navbar');

/**
 * Inline SVG icon helper (no external icon libs required)
 */
if (!defined('GIGTUNE_CANON_ARTIST_PROFILE_HELPERS_LOADED')) {
define('GIGTUNE_CANON_ARTIST_PROFILE_HELPERS_LOADED', true);

function gigtune_svg_icon_profile($name, $class = 'w-5 h-5') {
  $class_attr = esc_attr($class);

  switch ($name) {
    case 'star':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12 2.5l2.95 6 6.62.96-4.79 4.67 1.13 6.6L12 17.9l-5.91 3.11 1.13-6.6L2.43 9.46l6.62-.96L12 2.5Z"/>
      </svg>';

    case 'checkcircle':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M22 4 12 14.01l-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>';

    case 'location':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M12 22s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11Z" stroke="currentColor" stroke-width="2"/>
        <path d="M12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" stroke="currentColor" stroke-width="2"/>
      </svg>';

    case 'money':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M3 7h18v10H3V7Z" stroke="currentColor" stroke-width="2"/>
        <path d="M7 7V5h10v2" stroke="currentColor" stroke-width="2"/>
        <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="2"/>
      </svg>';

    case 'calendar':
      return '<svg class="'.$class_attr.'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M7 3v3M17 3v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        <path d="M3 9h18" stroke="currentColor" stroke-width="2"/>
        <path d="M5 6h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2"/>
      </svg>';

    default:
      return '';
  }
}

/**
 * Helpers for mapping the codex artist payload shape into UI fields
 */
function gigtune_profile_get($arr, $path, $default = null) {
  // Simple dot-path getter
  if (!is_array($arr) || $path === '') return $default;
  $parts = explode('.', $path);
  $cur = $arr;
  foreach ($parts as $p) {
    if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
    $cur = $cur[$p];
  }
  return $cur;
}

function gigtune_profile_rating($artist) {
  $r = gigtune_profile_get($artist, 'ratings.performance_avg', null);
  if (is_numeric($r)) return (float)$r;
  $r = gigtune_profile_get($artist, 'ratings.reliability_avg', null);
  if (is_numeric($r)) return (float)$r;
  return null;
}

function gigtune_profile_role($artist) {
  $terms = gigtune_profile_get($artist, 'terms.performer_type', []);
  if (is_array($terms) && !empty($terms) && !empty($terms[0]['name'])) return (string)$terms[0]['name'];
  return 'Artist';
}

function gigtune_profile_location($artist) {
  $loc = gigtune_profile_get($artist, 'availability.base_area', '');
  return is_string($loc) ? $loc : '';
}

function gigtune_profile_price($artist) {
  $min = gigtune_profile_get($artist, 'pricing.min', null);
  $max = gigtune_profile_get($artist, 'pricing.max', null);
  if (!is_numeric($min) && !is_numeric($max)) return '';

  $fmt = function($n){ return 'R' . number_format((float)$n, 0, '.', ','); };
  if (is_numeric($min) && is_numeric($max)) return $fmt($min) . ' - ' . $fmt($max);
  if (is_numeric($min)) return 'From ' . $fmt($min);
  return 'Up to ' . $fmt($max);
}

function gigtune_profile_tags($artist, $max = 6) {
  $out = [];
  $terms = gigtune_profile_get($artist, 'terms', []);
  if (!is_array($terms)) return $out;

  // Pull from all taxonomies, but keep it tidy
  foreach ($terms as $tx => $list) {
    if (!is_array($list)) continue;
    foreach ($list as $t) {
      if (!empty($t['name'])) {
        $out[] = (string)$t['name'];
        if (count($out) >= $max) return $out;
      }
    }
  }
  return $out;
}

function gigtune_profile_extract_youtube_id($url) {
  $url = trim((string) $url);
  if ($url === '') return '';
  $parts = wp_parse_url($url);
  if (!is_array($parts)) return '';
  $host = strtolower((string) ($parts['host'] ?? ''));
  $path = (string) ($parts['path'] ?? '');
  $query = [];
  if (!empty($parts['query'])) {
    parse_str((string) $parts['query'], $query);
  }

  if (strpos($host, 'youtu.be') !== false) {
    $id = trim($path, '/');
    return preg_match('/^[A-Za-z0-9_-]{6,15}$/', $id) ? $id : '';
  }
  if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtube-nocookie.com') !== false) {
    if (!empty($query['v']) && preg_match('/^[A-Za-z0-9_-]{6,15}$/', (string) $query['v'])) {
      return (string) $query['v'];
    }
    if (preg_match('~/(embed|shorts)/([A-Za-z0-9_-]{6,15})~', $path, $m)) {
      return (string) $m[2];
    }
  }
  return '';
}

function gigtune_profile_is_direct_video_url($url) {
  $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
  $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
  return in_array($ext, ['mp4', 'webm', 'ogg', 'ogv', 'm4v'], true);
}

function gigtune_profile_is_soundcloud_url($url) {
  $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
  return $host !== '' && strpos($host, 'soundcloud.com') !== false;
}
}

/**
 * Pick artist
 */
$force_placeholders = (isset($_GET['placeholders']) && $_GET['placeholders'] === '1');
$has_live_api = function_exists('gigtune_core_get_artist') && function_exists('gigtune_core_get_artists');

$artist_id = 0;
if (isset($_GET['artist_id'])) {
  $artist_id = absint($_GET['artist_id']);
}
if ($artist_id <= 0 && isset($_GET['id'])) {
  $artist_id = absint($_GET['id']);
}
$artist_slug = isset($_GET['artist_slug']) ? sanitize_title(wp_unslash($_GET['artist_slug'])) : '';

$artist = null;
$using_live = false;

if ($has_live_api && !$force_placeholders) {
  // 1) artist_id
  if ($artist_id > 0) {
    $res = gigtune_core_get_artist($artist_id);
    if (!is_wp_error($res) && is_array($res)) {
      $artist = $res;
      $using_live = true;
    }
  }

  // 2) artist_slug (resolve to id via gigtune_core_get_artists search)
  if (!$using_live && $artist_slug !== '') {
    $list = gigtune_core_get_artists(['per_page' => 1, 'artist_slug' => $artist_slug]);
    if (is_array($list) && !empty($list['items'][0]['id'])) {
      $res = gigtune_core_get_artist((int)$list['items'][0]['id']);
      if (!is_wp_error($res) && is_array($res)) {
        $artist = $res;
        $using_live = true;
      }
    }
  }

  // 3) fallback to first artist
  if (!$using_live) {
    $list = gigtune_core_get_artists(['per_page' => 1, 'paged' => 1]);
    if (is_array($list) && !empty($list['items'][0]['id'])) {
      $res = gigtune_core_get_artist((int)$list['items'][0]['id']);
      if (!is_wp_error($res) && is_array($res)) {
        $artist = $res;
        $using_live = true;
      }
    }
  }
}

/**
 * Placeholders (Gemini vibe)
 */
$placeholder = [
  'title' => 'The Midnight Jazz Trio',
  'role' => 'Jazz Band',
  'location' => 'Cape Town',
  'rating' => 4.9,
  'price' => 'R5,000 - R12,000',
  'verified' => true,
  'tags' => ['Jazz', 'Swing', 'Lounge', 'Corporate', 'Wedding', 'Private'],
  'about' => 'A premium live-act specialising in smooth jazz, swing classics, and sophisticated lounge sets—perfect for corporate events and high-end private functions.',
  'demo_videos' => [
    ['title' => 'Live at Rooftop Session', 'url' => '#'],
    ['title' => 'Studio Showcase', 'url' => '#'],
  ],
];

// Extract UI fields
$title = $using_live ? (string) gigtune_profile_get($artist, 'title', 'Artist') : $placeholder['title'];
$role = $using_live ? gigtune_profile_role($artist) : $placeholder['role'];
$location = $using_live ? gigtune_profile_location($artist) : $placeholder['location'];
$rating = $using_live ? gigtune_profile_rating($artist) : (float)$placeholder['rating'];
$rating_display = ($rating !== null && $rating !== '') ? rtrim(rtrim(number_format((float)$rating, 1, '.', ''), '0'), '.') : '—';
$price = $using_live ? gigtune_profile_price($artist) : $placeholder['price'];
$tags = $using_live ? gigtune_profile_tags($artist, 6) : $placeholder['tags'];
$about = $using_live ? (string) gigtune_profile_get($artist, 'content', $placeholder['about']) : $placeholder['about'];
$demo_videos = $using_live ? (array) gigtune_profile_get($artist, 'demo_videos', []) : $placeholder['demo_videos'];
$photo_url = $using_live ? (string) gigtune_profile_get($artist, 'photo.url', '') : '';
$banner_url = $using_live ? (string) gigtune_profile_get($artist, 'banner.url', '') : '';

// Safety for about (strip tags if WP content was stored with markup)
$about = wp_strip_all_tags($about);
$about = trim($about);

// Build "Book" link to your existing booking page
$book_url = esc_url(site_url('/book-an-artist'));
if ($using_live && !empty($artist['id'])) {
  // pass along artist_id so booking form can prefill later
  $book_url = esc_url(add_query_arg('artist_id', (int)$artist['id'], site_url('/book-an-artist')));
}

?>

<div class="pt-12 pb-24 w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 animate-fade-in">
  <!-- Top Profile Header -->
  <div class="bg-slate-800 rounded-2xl border border-slate-700 overflow-hidden">
    <div class="h-56 md:h-64 bg-slate-700 relative" <?php echo $banner_url !== '' ? 'style="background-image:url(' . esc_url($banner_url) . ');background-size:cover;background-position:center;"' : ''; ?>>
      <div class="w-full h-full bg-gradient-to-t from-slate-900 to-transparent absolute bottom-0 z-10"></div>
      <div class="absolute inset-0 flex items-center justify-center text-slate-500">
        <?php echo gigtune_svg_icon_profile('checkcircle', 'w-16 h-16 opacity-10'); ?>
      </div>
      <?php if ($photo_url !== ''): ?>
        <div class="absolute inset-0 flex items-center justify-center z-20">
          <img src="<?php echo esc_url($photo_url); ?>" alt="<?php echo esc_attr($title); ?>" class="w-28 h-28 md:w-32 md:h-32 rounded-full object-cover border border-slate-600 shadow-lg" />
        </div>
      <?php endif; ?>

      <div class="absolute top-4 right-4 z-20">
        <span class="bg-slate-900/80 backdrop-blur text-white text-xs font-bold px-2 py-1 rounded flex items-center gap-1">
          <span class="w-3 h-3 text-yellow-400">
            <?php echo gigtune_svg_icon_profile('star', 'w-3 h-3 text-yellow-400 fill-yellow-400'); ?>
          </span>
          <?php echo esc_html($rating_display); ?>
        </span>
      </div>
    </div>

    <div class="p-6 md:p-8">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">
        <div class="min-w-0">
          <div class="flex items-center gap-3 mb-2">
            <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white">
              <?php echo esc_html($title); ?>
            </h1>
            <span class="text-blue-400 w-5 h-5 flex-shrink-0">
              <?php echo gigtune_svg_icon_profile('checkcircle', 'w-5 h-5 text-blue-400'); ?>
            </span>
          </div>

          <p class="text-slate-400 text-sm md:text-base">
            <?php echo esc_html($role); ?>
            <?php if ($location !== '') : ?>
              <span class="text-slate-600">•</span>
              <?php echo esc_html($location); ?>
            <?php endif; ?>
          </p>

          <?php if (!empty($tags)) : ?>
            <div class="flex flex-wrap gap-2 mt-4">
              <?php foreach ($tags as $tag) : ?>
                <span class="text-xs bg-slate-900 text-slate-400 px-2 py-1 rounded border border-slate-700">
                  <?php echo esc_html($tag); ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Right CTA panel -->
        <div class="w-full md:w-[340px]">
          <div class="bg-slate-900/40 border border-slate-700 rounded-xl p-5">
            <div class="flex items-center gap-2 text-slate-300 text-sm mb-3">
              <span class="w-5 h-5 text-slate-400"><?php echo gigtune_svg_icon_profile('money', 'w-5 h-5'); ?></span>
              <span class="font-semibold text-white"><?php echo esc_html($price !== '' ? $price : 'Pricing on request'); ?></span>
            </div>

            <div class="flex items-center gap-2 text-slate-300 text-sm mb-4">
              <span class="w-5 h-5 text-slate-400"><?php echo gigtune_svg_icon_profile('calendar', 'w-5 h-5'); ?></span>
              <span>Check availability & request a booking</span>
            </div>

            <a
              href="<?php echo $book_url; ?>"
              class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20 w-full"
            >
              Book this Artist
            </a>

            <div class="mt-3 text-xs text-slate-500">
              <?php echo $using_live ? 'Live profile (GigTune Core)' : 'Placeholder profile (UI preview)'; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Content Sections -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
    <!-- About -->
    <div class="lg:col-span-2 bg-slate-800 rounded-2xl border border-slate-700 p-6">
      <h2 class="text-xl font-bold text-white mb-3">About</h2>
      <p class="text-slate-300 leading-relaxed">
        <?php echo esc_html($about !== '' ? $about : 'No biography yet.'); ?>
      </p>
    </div>

    <!-- Quick facts -->
    <div class="bg-slate-800 rounded-2xl border border-slate-700 p-6">
      <h2 class="text-xl font-bold text-white mb-3">Quick Facts</h2>

      <div class="space-y-3 text-sm">
        <div class="flex items-start justify-between gap-3">
          <span class="text-slate-400">Performer Type</span>
          <span class="text-slate-200 font-semibold text-right"><?php echo esc_html($role); ?></span>
        </div>

        <div class="flex items-start justify-between gap-3">
          <span class="text-slate-400">Base Area</span>
          <span class="text-slate-200 font-semibold text-right"><?php echo esc_html($location !== '' ? $location : '—'); ?></span>
        </div>

        <div class="flex items-start justify-between gap-3">
          <span class="text-slate-400">Rating</span>
          <span class="text-slate-200 font-semibold text-right"><?php echo esc_html($rating_display); ?></span>
        </div>

        <div class="flex items-start justify-between gap-3">
          <span class="text-slate-400">Pricing</span>
          <span class="text-slate-200 font-semibold text-right"><?php echo esc_html($price !== '' ? $price : 'On request'); ?></span>
        </div>
      </div>
    </div>
  </div>

  <?php
    $availability_artist_id = 0;
    if ($using_live && !empty($artist['id'])) {
      $availability_artist_id = (int) $artist['id'];
    } elseif ($artist_id > 0) {
      $availability_artist_id = (int) $artist_id;
    }
  ?>

  <div class="mt-8">
    <?php echo do_shortcode('[gigtune_artist_availability_summary artist_id="' . esc_attr((string) $availability_artist_id) . '" heading="Artist availability"]'); ?>
  </div>

  <!-- Demo Videos -->
  <div class="mt-8 bg-slate-800 rounded-2xl border border-slate-700 p-6">
    <div class="flex items-center justify-between gap-4 mb-4">
      <h2 class="text-xl font-bold text-white">Demo</h2>
      <span class="text-xs text-slate-500">Videos / Samples</span>
    </div>

    <?php if (!empty($demo_videos) && is_array($demo_videos)) : ?>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($demo_videos as $v) : ?>
          <?php
            $vt = !empty($v['title']) ? (string)$v['title'] : 'Demo Video';
            $vu = !empty($v['url']) ? (string)$v['url'] : '';
            $youtube_id = gigtune_profile_extract_youtube_id($vu);
            $is_soundcloud = gigtune_profile_is_soundcloud_url($vu);
            $is_direct_video = gigtune_profile_is_direct_video_url($vu) || (isset($v['type']) && $v['type'] === 'upload');
          ?>
          <div class="bg-slate-900/40 border border-slate-700 rounded-xl p-4">
            <div class="text-white font-semibold mb-3"><?php echo esc_html($vt); ?></div>

            <?php if ($vu !== '' && $is_direct_video) : ?>
              <video controls class="w-full rounded-lg mb-3" src="<?php echo esc_url($vu); ?>" preload="metadata" playsinline></video>
              <div class="text-slate-400 text-sm">Uploaded demo video</div>
            <?php elseif ($youtube_id !== '') : ?>
              <div class="aspect-video rounded-lg overflow-hidden mb-3 border border-slate-700">
                <iframe
                  class="w-full h-full"
                  src="<?php echo esc_url('https://www.youtube-nocookie.com/embed/' . rawurlencode($youtube_id)); ?>"
                  title="<?php echo esc_attr($vt); ?>"
                  loading="lazy"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                  allowfullscreen
                ></iframe>
              </div>
              <div class="text-slate-400 text-sm">YouTube demo</div>
            <?php elseif ($vu !== '' && $is_soundcloud) : ?>
              <div class="rounded-lg overflow-hidden mb-3 border border-slate-700">
                <iframe
                  class="w-full h-[166px]"
                  src="<?php echo esc_url('https://w.soundcloud.com/player/?url=' . rawurlencode($vu) . '&color=%236366f1&auto_play=false&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false'); ?>"
                  title="<?php echo esc_attr($vt); ?>"
                  loading="lazy"
                  allow="autoplay"
                ></iframe>
              </div>
              <div class="text-slate-400 text-sm">SoundCloud demo</div>
            <?php elseif ($vu !== '') : ?>
              <a href="<?php echo esc_url($vu); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg bg-white/10 px-3 py-2 text-sm text-white hover:bg-white/15">
                Open demo link
              </a>
            <?php else : ?>
              <div class="text-slate-500 text-sm">Demo unavailable.</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else : ?>
      <div class="text-slate-500">No demos uploaded yet.</div>
    <?php endif; ?>
  </div>

  <!-- Helpers: fade -->
  <style>
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
  </style>
</div>

<?php
get_footer();
