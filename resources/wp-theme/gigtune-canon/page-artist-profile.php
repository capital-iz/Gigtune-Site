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

function gigtune_profile_youtube_thumbnail($youtube_id) {
  $youtube_id = trim((string) $youtube_id);
  if ($youtube_id === '') return '';
  return 'https://i.ytimg.com/vi/' . rawurlencode($youtube_id) . '/hqdefault.jpg';
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
              <div class="gt-demo-player relative overflow-hidden rounded-lg border border-slate-700 bg-black mb-3" data-demo-player="1">
                <video
                  class="w-full aspect-video bg-black"
                  data-demo-video="1"
                  data-src="<?php echo esc_url($vu); ?>"
                  preload="none"
                  playsinline
                ></video>
                <button type="button" class="gt-demo-overlay absolute inset-0 z-10 flex items-center justify-center gap-2 text-white" data-demo-overlay-play="1">
                  <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-blue-500 shadow-lg">
                    <svg viewBox="0 0 24 24" class="w-5 h-5 fill-white" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                  </span>
                  <span class="text-sm font-semibold tracking-wide">Play Demo</span>
                </button>
                <div class="gt-demo-controls absolute inset-x-0 bottom-0 z-20 flex items-center gap-2 px-3 py-2">
                  <button type="button" data-demo-toggle="1" class="rounded-md bg-white/10 px-2 py-1 text-xs font-semibold text-white hover:bg-white/20">Play</button>
                  <input type="range" data-demo-seek="1" min="0" max="100" value="0" class="gt-demo-range h-1 w-full cursor-pointer rounded-full" />
                  <span data-demo-time="1" class="whitespace-nowrap text-[11px] font-medium text-slate-200">0:00 / 0:00</span>
                  <button type="button" data-demo-mute="1" class="rounded-md bg-white/10 px-2 py-1 text-xs font-semibold text-white hover:bg-white/20">Mute</button>
                  <button type="button" data-demo-fullscreen="1" class="rounded-md bg-white/10 px-2 py-1 text-xs font-semibold text-white hover:bg-white/20">Full</button>
                </div>
              </div>
              <div class="text-slate-400 text-sm">Uploaded demo video</div>
            <?php elseif ($youtube_id !== '') : ?>
              <div class="gt-demo-embed relative aspect-video rounded-lg overflow-hidden mb-3 border border-slate-700 bg-black" data-demo-embed-wrap="1">
                <button
                  type="button"
                  class="absolute inset-0 block w-full text-left"
                  data-demo-embed-activate="1"
                  data-embed-url="<?php echo esc_url('https://www.youtube-nocookie.com/embed/' . rawurlencode($youtube_id) . '?autoplay=1'); ?>"
                  data-embed-allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                >
                  <img src="<?php echo esc_url(gigtune_profile_youtube_thumbnail($youtube_id)); ?>" alt="<?php echo esc_attr($vt); ?>" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                  <span class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/30 to-black/35"></span>
                  <span class="absolute inset-x-4 bottom-4 flex items-center justify-between">
                    <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-100">
                      <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-blue-500 shadow-lg">
                        <svg viewBox="0 0 24 24" class="w-4 h-4 fill-white" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                      </span>
                      YouTube
                    </span>
                    <span class="rounded-md bg-white/15 px-2 py-1 text-[11px] font-semibold text-white">Tap to load</span>
                  </span>
                </button>
              </div>
              <div class="text-slate-400 text-sm">YouTube demo</div>
            <?php elseif ($vu !== '' && $is_soundcloud) : ?>
              <div class="gt-demo-embed relative rounded-lg overflow-hidden mb-3 border border-slate-700 bg-black h-[166px]" data-demo-embed-wrap="1">
                <button
                  type="button"
                  class="absolute inset-0 block w-full text-left"
                  data-demo-embed-activate="1"
                  data-embed-url="<?php echo esc_url('https://w.soundcloud.com/player/?url=' . rawurlencode($vu) . '&color=%236366f1&auto_play=true&hide_related=true&show_comments=false&show_user=true&show_reposts=false&show_teaser=false'); ?>"
                  data-embed-class="w-full h-[166px]"
                  data-embed-allow="autoplay"
                >
                  <span class="absolute inset-0 bg-gradient-to-br from-slate-900 via-slate-950 to-blue-950"></span>
                  <span class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-black/30"></span>
                  <span class="absolute inset-x-4 bottom-4 flex items-center justify-between">
                    <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-100">
                      <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-blue-500 shadow-lg">
                        <svg viewBox="0 0 24 24" class="w-4 h-4 fill-white" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                      </span>
                      SoundCloud
                    </span>
                    <span class="rounded-md bg-white/15 px-2 py-1 text-[11px] font-semibold text-white">Tap to load</span>
                  </span>
                </button>
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
    .gt-demo-overlay { background: radial-gradient(circle at center, rgba(15,23,42,0.35) 0%, rgba(2,6,23,0.85) 72%); transition: opacity .2s ease; }
    .gt-demo-player.is-playing .gt-demo-overlay { opacity: 0; pointer-events: none; }
    .gt-demo-controls { background: linear-gradient(to top, rgba(2,6,23,.94), rgba(2,6,23,.38)); backdrop-filter: blur(6px); }
    .gt-demo-range { accent-color: #fbbf24; }
    @media (hover:hover) {
      .gt-demo-player:hover .gt-demo-overlay svg { transform: scale(1.04); }
    }
  </style>
  <script>
    (function () {
      function formatTime(seconds) {
        var value = Number(seconds);
        if (!Number.isFinite(value) || value < 0) value = 0;
        var mins = Math.floor(value / 60);
        var secs = Math.floor(value % 60);
        return mins + ':' + String(secs).padStart(2, '0');
      }

      function ensureSource(video) {
        if (!video) return false;
        if (video.getAttribute('src')) return true;
        var src = (video.dataset.src || '').trim();
        if (!src) return false;
        video.setAttribute('src', src);
        try { video.load(); } catch (e) {}
        return true;
      }

      function bindPlayer(root) {
        if (!root || root.dataset.bound === '1') return;
        root.dataset.bound = '1';
        var video = root.querySelector('[data-demo-video="1"]');
        if (!video) return;
        var overlayPlay = root.querySelector('[data-demo-overlay-play="1"]');
        var toggle = root.querySelector('[data-demo-toggle="1"]');
        var seek = root.querySelector('[data-demo-seek="1"]');
        var time = root.querySelector('[data-demo-time="1"]');
        var mute = root.querySelector('[data-demo-mute="1"]');
        var fullscreen = root.querySelector('[data-demo-fullscreen="1"]');

        function refreshState() {
          var isPlaying = !video.paused && !video.ended;
          root.classList.toggle('is-playing', isPlaying);
          if (toggle) toggle.textContent = isPlaying ? 'Pause' : 'Play';
          if (mute) mute.textContent = video.muted ? 'Unmute' : 'Mute';
        }

        function refreshProgress() {
          var duration = Number(video.duration);
          var current = Number(video.currentTime || 0);
          if (seek) {
            var percent = duration > 0 ? (current / duration) * 100 : 0;
            seek.value = String(Math.max(0, Math.min(100, percent)));
          }
          if (time) time.textContent = formatTime(current) + ' / ' + formatTime(duration);
        }

        function togglePlayback() {
          if (!ensureSource(video)) return;
          if (video.paused || video.ended) {
            video.play().catch(function () {});
          } else {
            video.pause();
          }
        }

        if (overlayPlay) overlayPlay.addEventListener('click', togglePlayback);
        if (toggle) toggle.addEventListener('click', togglePlayback);
        if (seek) {
          seek.addEventListener('input', function () {
            if (!ensureSource(video)) return;
            var duration = Number(video.duration);
            if (!Number.isFinite(duration) || duration <= 0) return;
            var next = (Number(seek.value || 0) / 100) * duration;
            if (Number.isFinite(next)) video.currentTime = next;
          });
        }
        if (mute) {
          mute.addEventListener('click', function () {
            video.muted = !video.muted;
            refreshState();
          });
        }
        if (fullscreen) {
          fullscreen.addEventListener('click', function () {
            if (typeof root.requestFullscreen === 'function') {
              root.requestFullscreen().catch(function () {});
            }
          });
        }

        video.addEventListener('play', refreshState);
        video.addEventListener('pause', refreshState);
        video.addEventListener('ended', refreshState);
        video.addEventListener('loadedmetadata', refreshProgress);
        video.addEventListener('timeupdate', refreshProgress);

        refreshState();
        refreshProgress();
      }

      function bindEmbed(button) {
        if (!button || button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', function () {
          var wrap = button.closest('[data-demo-embed-wrap="1"]');
          if (!wrap) return;
          var url = (button.dataset.embedUrl || '').trim();
          if (!url) return;
          var iframe = document.createElement('iframe');
          iframe.src = url;
          iframe.className = (button.dataset.embedClass || 'w-full h-full');
          iframe.setAttribute('title', 'Demo media');
          iframe.setAttribute('loading', 'lazy');
          iframe.setAttribute('allowfullscreen', 'allowfullscreen');
          iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
          iframe.setAttribute('allow', (button.dataset.embedAllow || 'autoplay; encrypted-media; picture-in-picture; fullscreen'));
          wrap.innerHTML = '';
          wrap.appendChild(iframe);
        });
      }

      function bindAll() {
        document.querySelectorAll('[data-demo-player="1"]').forEach(bindPlayer);
        document.querySelectorAll('[data-demo-embed-activate="1"]').forEach(bindEmbed);
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
      } else {
        bindAll();
      }
    })();
  </script>
</div>

<?php
get_footer();
