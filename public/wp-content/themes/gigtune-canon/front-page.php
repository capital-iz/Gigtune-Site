<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>


<main class="min-h-[calc(100vh-300px)] bg-slate-950 text-slate-200 font-sans selection:bg-purple-500/30 selection:text-white">

<?php
  $user = wp_get_current_user();
  $roles = is_user_logged_in() ? (array) $user->roles : [];
  $is_artist = in_array('gigtune_artist', $roles, true);
  $is_client = in_array('gigtune_client', $roles, true) || in_array('administrator', $roles, true);
  $hero_bg_file = get_template_directory() . '/assets/img/home-hero-logged-out.png';
  $hero_bg_ver = file_exists($hero_bg_file) ? (string) @filemtime($hero_bg_file) : (string) time();
  $hero_bg_url = get_template_directory_uri() . '/assets/img/home-hero-logged-out.png?v=' . rawurlencode($hero_bg_ver);
?>
<?php if (!is_user_logged_in()): ?>


  <!-- HERO (matches Gemini v1.2 layout/spacing) -->
  <section class="group relative isolate pt-24 pb-20 md:pb-32 overflow-hidden px-4">
    <img
      src="<?php echo esc_url($hero_bg_url); ?>"
      alt=""
      loading="eager"
      fetchpriority="high"
      decoding="async"
      class="pointer-events-none absolute inset-0 z-0 h-full w-full object-cover object-center opacity-70 gt-home-hero-bg"
      aria-hidden="true"
    />
    <div class="pointer-events-none absolute top-0 left-0 z-10 w-full h-full bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-purple-900/25 via-slate-900/70 to-slate-900"></div>

    <div class="relative z-20 w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 text-center">
      <span class="px-2 py-1 rounded-full text-xs font-medium border bg-purple-500/10 text-purple-400 border-purple-500/20 inline-block mb-6">
        The New Standard for Live Bookings
      </span>

      <h1 class="text-4xl sm:text-5xl md:text-7xl font-bold text-white tracking-tight mb-6 leading-tight transition-all duration-300 drop-shadow-[0_10px_28px_rgba(0,0,0,0.82)] group-hover:drop-shadow-[0_14px_36px_rgba(0,0,0,0.9)] group-hover:scale-[1.01]">
        Book reliable talent. <br />
        <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-purple-500">Without the guesswork.</span>
      </h1>

      <p class="text-lg md:text-xl text-slate-400 max-w-2xl mx-auto mb-10 px-4 transition-all duration-300 drop-shadow-[0_6px_20px_rgba(0,0,0,0.75)] group-hover:text-slate-200 group-hover:drop-shadow-[0_10px_26px_rgba(0,0,0,0.85)]">
        GigTune matches you with verified artists using fit-based scoring and protects your payment in Temporary Holding until the show is done.
      </p>

      <div class="flex flex-col sm:flex-row gap-4 justify-center w-full sm:w-auto px-4 sm:px-0">
        <a href="<?php echo esc_url(home_url('/artists/')); ?>"
           class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20 py-4 text-base w-full sm:w-auto">
          Find an Artist
        </a>
        <a href="<?php echo esc_url(home_url('/join/')); ?>"
           class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 py-4 text-base w-full sm:w-auto">
          Join as Pro
        </a>
      </div>
    </div>
  </section>

  <!-- TRUST & STATS BAR -->
  <section class="py-10 bg-slate-900/50 border-y border-slate-800">
    <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 grid grid-cols-2 md:grid-cols-4 gap-8 text-center md:text-left">
      <div class="flex flex-col md:flex-row items-center gap-3 justify-center md:justify-start">
        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 font-bold">✓</div>
        <div>
          <div class="text-white font-bold text-lg">100% Verified</div>
          <div class="text-slate-500 text-sm">Every artist is vetted</div>
        </div>
      </div>

      <div class="flex flex-col md:flex-row items-center gap-3 justify-center md:justify-start">
        <div class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400 font-bold">🔒</div>
        <div>
          <div class="text-white font-bold text-lg">Secure Temporary Holding</div>
          <div class="text-slate-500 text-sm">Money safe until showtime</div>
        </div>
      </div>

      <div class="flex flex-col md:flex-row items-center gap-3 justify-center md:justify-start">
        <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400 font-bold">⚡</div>
        <div>
          <div class="text-white font-bold text-lg">24h Payouts</div>
          <div class="text-slate-500 text-sm">Fast, automated payments</div>
        </div>
      </div>

      <div class="flex flex-col md:flex-row items-center gap-3 justify-center md:justify-start">
        <div class="w-10 h-10 rounded-xl bg-yellow-500/10 border border-yellow-500/20 flex items-center justify-center text-yellow-400 font-bold">★</div>
        <div>
          <div class="text-white font-bold text-lg">Reliability Scores</div>
          <div class="text-slate-500 text-sm">Book with confidence</div>
        </div>
      </div>
    </div>
  </section>

  <!-- BUILT FOR BOTH SIDES -->
  <section class="py-20 md:py-24 bg-slate-950">
    <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16">
      <div class="mb-8 md:mb-12 text-center">
        <h2 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white mb-3 md:mb-4">Built for Both Sides of the Stage</h2>
        <p class="text-slate-400 text-base md:text-lg max-w-2xl mx-auto">A professional ecosystem where transparency bridges the gap between talent and organisers.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-8 md:gap-12 mt-12 md:mt-16">
        <!-- Organisers -->
        <div class="bg-slate-900/50 rounded-2xl p-6 md:p-8 border border-slate-800 hover:border-blue-500/30 transition-all">
          <div class="flex items-center gap-4 mb-6">
            <div class="w-14 h-14 bg-blue-500/10 rounded-xl flex items-center justify-center text-blue-400 font-bold">👤</div>
            <div>
              <h3 class="text-2xl font-bold text-white">For Organisers</h3>
              <p class="text-slate-400">Stop guessing availability.</p>
            </div>
          </div>

          <ul class="space-y-4 mb-8">
            <li class="flex gap-4 items-start">
              <div class="mt-1 min-w-[20px] text-blue-500 font-bold">✓</div>
              <div>
                <div class="text-white font-bold text-base">Fit-Based Discovery</div>
                <div class="text-slate-400 text-sm">Don't just find who's nearby. Find the exact genre, budget, and vibe match for your event.</div>
              </div>
            </li>
            <li class="flex gap-4 items-start">
              <div class="mt-1 min-w-[20px] text-blue-500 font-bold">✓</div>
              <div>
                <div class="text-white font-bold text-base">Zero Risk Booking</div>
                <div class="text-slate-400 text-sm">Your payment is held in our Temporary Holding Vault and only released after a successful performance.</div>
              </div>
            </li>
            <li class="flex gap-4 items-start">
              <div class="mt-1 min-w-[20px] text-blue-500 font-bold">✓</div>
              <div>
                <div class="text-white font-bold text-base">Reliability Metrics</div>
                <div class="text-slate-400 text-sm">See an artist's response time and “show-up” rate before you book. No flakes allowed.</div>
              </div>
            </li>
          </ul>

          <a href="<?php echo esc_url(home_url('/artists/')); ?>"
             class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20 w-full">
            Find Talent Now
          </a>
        </div>

        <!-- Artists -->
        <div class="bg-slate-900/50 rounded-2xl p-6 md:p-8 border border-slate-800 hover:border-purple-500/30 transition-all">
          <div class="flex items-center gap-4 mb-6">
            <div class="w-14 h-14 bg-purple-500/10 rounded-xl flex items-center justify-center text-purple-400 font-bold">🎵</div>
            <div>
              <h3 class="text-2xl font-bold text-white">For Artists</h3>
              <p class="text-slate-400">Play more, admin less.</p>
            </div>
          </div>

          <ul class="space-y-4 mb-8">
            <li class="flex gap-4 items-start">
              <div class="mt-1 min-w-[20px] text-purple-500 font-bold">✓</div>
              <div>
                <div class="text-white font-bold text-base">Guaranteed Payment</div>
                <div class="text-slate-400 text-sm">Client funds are captured in Temporary Holding before you accept. No chasing invoices, ever.</div>
              </div>
            </li>
            <li class="flex gap-4 items-start">
              <div class="mt-1 min-w-[20px] text-purple-500 font-bold">✓</div>
              <div>
                <div class="text-white font-bold text-base">Serious Inquiries Only</div>
                <div class="text-slate-400 text-sm">We filter leads by your budget and location rules. No more “exposure” gigs.</div>
              </div>
            </li>
            <li class="flex gap-4 items-start">
              <div class="mt-1 min-w-[20px] text-purple-500 font-bold">✓</div>
              <div>
                <div class="text-white font-bold text-base">Your Professional OS</div>
                <div class="text-slate-400 text-sm">Manage availability, bookings, and earnings in one professional dashboard.</div>
              </div>
            </li>
          </ul>

          <a href="<?php echo esc_url(home_url('/join/')); ?>"
             class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 w-full">
            Join as Pro
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- FROM SEARCH TO STAGE IN 3 STEPS -->
  <section class="py-20 md:py-24 bg-slate-900 border-t border-slate-800">
    <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16">
      <div class="mb-16 md:text-center max-w-3xl mx-auto">
        <span class="px-2 py-1 rounded-full text-xs font-medium border bg-blue-500/10 text-blue-400 border-blue-500/20 inline-block mb-4">The Operating System for Live Music</span>
        <h2 class="text-3xl md:text-4xl font-bold text-white mb-4">From Search to Stage in 3 Steps</h2>
        <p class="text-slate-400 text-lg">We've stripped away the emails, calls, and contracts to create the fastest booking experience in the industry.</p>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-12">
        <div class="relative pl-8 md:pl-0">
          <div class="text-7xl md:text-8xl font-bold text-slate-800 absolute -top-8 -left-2 md:-top-10 md:-left-6 -z-10 select-none">1</div>
          <h3 class="text-xl font-bold text-white mb-3 flex items-center gap-2">
            <span class="text-blue-500">⌕</span> Discovery
          </h3>
          <p class="text-slate-400 leading-relaxed">
            Filter by date, location, and budget to find available artists instantly. Watch demos and check reliability scores.
          </p>
        </div>

        <div class="relative pl-8 md:pl-0">
          <div class="text-7xl md:text-8xl font-bold text-slate-800 absolute -top-8 -left-2 md:-top-10 md:-left-6 -z-10 select-none">2</div>
          <h3 class="text-xl font-bold text-white mb-3 flex items-center gap-2">
            <span class="text-purple-500">🔒</span> Secure
          </h3>
          <p class="text-slate-400 leading-relaxed">
            Send a request. Once accepted, funds are held in Temporary Holding. Contracts are generated automatically.
          </p>
        </div>

        <div class="relative pl-8 md:pl-0">
          <div class="text-7xl md:text-8xl font-bold text-slate-800 absolute -top-8 -left-2 md:-top-10 md:-left-6 -z-10 select-none">3</div>
          <h3 class="text-xl font-bold text-white mb-3 flex items-center gap-2">
            <span class="text-emerald-500">↗</span> Performance
          </h3>
          <p class="text-slate-400 leading-relaxed">
            The show goes on. Funds are released automatically 24 hours later if no disputes are raised.
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- PAYMENT METHODS -->
  <section class="py-12 bg-slate-950 border-t border-slate-900">
    <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 text-center">
      <h3 class="text-slate-500 text-sm font-semibold uppercase tracking-wider mb-8">Secure Payments Supported in South Africa</h3>
      <div class="flex flex-wrap justify-center gap-x-8 gap-y-6 md:gap-16 items-center opacity-60">
        <div class="flex items-center gap-2 text-slate-300 font-bold text-lg md:text-2xl"><span class="text-white">💳</span> VISA</div>
        <div class="flex items-center gap-2 text-slate-300 font-bold text-lg md:text-2xl"><span class="text-white">💳</span> mastercard</div>
        <div class="flex items-center gap-2 text-slate-300 font-bold text-lg md:text-2xl"><span class="text-yellow-500">⚡</span> Card Checkout (YOCO)</div>
        <div class="flex items-center gap-2 text-slate-300 font-bold text-lg md:text-2xl"><span class="text-blue-400">▦</span> SnapScan</div>
      </div>
    </div>
  </section>

  <!-- FINAL CTA -->
  <section class="py-20 md:py-24 bg-gradient-to-br from-slate-900 to-purple-900/20 text-center border-t border-slate-800">
    <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16">
      <h2 class="text-3xl md:text-4xl font-bold text-white mb-6">Ready to upgrade your experience?</h2>
      <p class="text-lg md:text-xl text-slate-400 mb-10 max-w-2xl mx-auto">Join thousands of venues, organisers, and musicians using GigTune to professionalise the industry.</p>

      <div class="flex flex-col sm:flex-row gap-4 justify-center w-full sm:w-auto px-4 sm:px-0">
        <a href="<?php echo esc_url(home_url('/artists/')); ?>"
           class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20 px-8 py-4 w-full sm:w-auto">
          Browse Artists
        </a>
        <?php
          if (is_user_logged_in()) {
            $u = wp_get_current_user();
            $roles = ($u instanceof WP_User) ? (array) $u->roles : [];
            $dash = home_url('/my-account-page/');
            $label = 'Go to Dashboard';
            if (in_array('gigtune_artist', $roles, true)) {
              $dash = home_url('/artist-dashboard/');
              $label = 'Artist Dashboard';
            } elseif (in_array('gigtune_client', $roles, true)) {
              $dash = home_url('/client-dashboard/');
              $label = 'Client Dashboard';
            }
            ?>
            <a href="<?php echo esc_url($dash); ?>"
               class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 px-8 py-4 w-full sm:w-auto">
              <?php echo esc_html($label); ?>
            </a>
          <?php } else { ?>
            <a href="<?php echo esc_url(home_url('/join/')); ?>"
               class="px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 px-8 py-4 w-full sm:w-auto">
              Create Account
            </a>
          <?php } ?>
      </div>
    </div>
  </section>


<?php else: ?>
  <section class="py-16 md:py-20 px-4">
    <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16">
      <?php
        $nav_items = $is_artist
          ? [
              ['id' => 'gt-dashboard-overview', 'label' => 'Overview'],
              ['id' => 'gt-artist-actions', 'label' => 'Quick actions'],
              ['id' => 'gt-artist-snapshot', 'label' => 'Snapshot'],
              ['id' => 'gt-artist-dashboard', 'label' => 'Dashboard'],
              ['id' => 'gt-artist-opps', 'label' => 'Opportunities'],
            ]
          : [
              ['id' => 'gt-dashboard-overview', 'label' => 'Overview'],
              ['id' => 'gt-client-actions', 'label' => 'Quick actions'],
              ['id' => 'gt-client-snapshot', 'label' => 'Snapshot'],
              ['id' => 'gt-client-post-applicants', 'label' => 'Post applicants'],
              ['id' => 'gt-client-dashboard', 'label' => 'Bookings'],
              ['id' => 'gt-client-featured', 'label' => 'Featured artists'],
              ['id' => 'gt-client-profile', 'label' => 'Profile'],
            ];
      ?>

      <div class="gt-dashboard-layout">
        <button type="button" class="gt-dashboard-toggle" aria-controls="gt-dashboard-drawer" aria-expanded="false">
          <span class="sr-only">Toggle dashboard menu</span>
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M8 5l8 7-8 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>

        <div class="gt-dashboard-backdrop" aria-hidden="true"></div>

        <aside class="gt-dashboard-drawer" id="gt-dashboard-drawer">
          <div class="gt-dashboard-nav-inner">
            <div class="text-xs uppercase tracking-[0.2em] text-slate-500 mb-3">Dashboard</div>
            <div class="gt-dashboard-nav-links">
              <?php foreach ($nav_items as $item): ?>
                <a href="#<?php echo esc_attr($item['id']); ?>" class="gt-dashboard-nav-link">
                  <?php echo esc_html($item['label']); ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </aside>

        <div class="gt-dashboard-content">
          <div class="mb-8 gt-dashboard-section" id="gt-dashboard-overview">
            <h1 class="text-3xl md:text-4xl font-bold text-white">Welcome back, <?php echo esc_html($user->display_name); ?></h1>
            <p class="text-slate-400 mt-2"><?php echo $is_artist ? 'Your artist control center for bookings and opportunities.' : 'Your client hub for booking and tracking artists.'; ?></p>
            <?php if ($is_artist): ?>
              <div class="mt-6 grid gap-4 gt-dashboard-overview-cards">
                <a href="<?php echo esc_url(home_url('/open-posts/')); ?>" class="gt-dashboard-overview-card">
                  <div class="text-xs text-slate-400">Browse Client Posts</div>
                  <div class="text-lg text-white font-semibold mt-2">Open gigs</div>
                </a>
                <a href="<?php echo esc_url(home_url('/wp-login.php?action=logout&redirect_to=' . rawurlencode(home_url('/')))); ?>" class="gt-dashboard-overview-card gt-dashboard-overview-card-danger">
                  <div class="text-xs text-slate-400">Account</div>
                  <div class="text-lg text-white font-semibold mt-2">Log out</div>
                </a>
              </div>
            <?php else: ?>
              <div class="mt-6 grid gap-4 gt-dashboard-overview-cards">
                <a href="<?php echo esc_url(home_url('/posts-page/')); ?>" class="gt-dashboard-overview-card">
                  <div class="text-xs text-slate-400">Create a Gig Post</div>
                  <div class="text-lg text-white font-semibold mt-2">Post your needs</div>
                </a>
                <a href="<?php echo esc_url(home_url('/open-posts/')); ?>" class="gt-dashboard-overview-card">
                  <div class="text-xs text-slate-400">Browse Client Posts</div>
                  <div class="text-lg text-white font-semibold mt-2">Open gigs</div>
                </a>
                <a href="<?php echo esc_url(home_url('/wp-login.php?action=logout&redirect_to=' . rawurlencode(home_url('/')))); ?>" class="gt-dashboard-overview-card gt-dashboard-overview-card-danger">
                  <div class="text-xs text-slate-400">Account</div>
                  <div class="text-lg text-white font-semibold mt-2">Log out</div>
                </a>
              </div>
            <?php endif; ?>
          </div>

      <?php if ($is_artist): ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-10 gt-dashboard-section" id="gt-artist-actions">
          <a href="<?php echo esc_url(home_url('/artist-availability/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Update Availability</div>
            <div class="text-lg text-white font-semibold mt-2">Manage schedule</div>
          </a>
          <a href="<?php echo esc_url(home_url('/artist-dashboard/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Booking Requests</div>
            <div class="text-lg text-white font-semibold mt-2">Review now</div>
          </a>
          <a href="<?php echo esc_url(home_url('/posts-page/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Browse Client Posts</div>
            <div class="text-lg text-white font-semibold mt-2">Open gigs</div>
          </a>
          <a href="<?php echo esc_url(home_url('/artist-profile/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Public Profile</div>
            <div class="text-lg text-white font-semibold mt-2">View profile</div>
          </a>
        </div>

        <div class="mb-10 gt-dashboard-section" id="gt-artist-snapshot">
          <?php echo do_shortcode('[gigtune_artist_home_snapshot]'); ?>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 gt-dashboard-grid">
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6 gt-dashboard-section" id="gt-artist-dashboard">
            <h2 class="text-lg font-semibold text-white mb-4">Dashboard Snapshot</h2>
            <?php echo do_shortcode('[gigtune_artist_dashboard]'); ?>
          </div>
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6 gt-dashboard-section" id="gt-artist-opps">
            <h2 class="text-lg font-semibold text-white mb-4">Opportunities</h2>
            <?php echo do_shortcode('[gigtune_artist_feed]'); ?>
          </div>
        </div>
      <?php else: ?>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-10 gt-dashboard-section" id="gt-client-actions">
          <a href="<?php echo esc_url(home_url('/browse-artists/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Discover Artists</div>
            <div class="text-lg text-white font-semibold mt-2">Browse talent</div>
          </a>
          <a href="<?php echo esc_url(home_url('/book-an-artist/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Book an Artist</div>
            <div class="text-lg text-white font-semibold mt-2">Start a request</div>
          </a>
          <a href="<?php echo esc_url(home_url('/posts-page/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">Create a Gig Post</div>
            <div class="text-lg text-white font-semibold mt-2">Post your needs</div>
          </a>
          <a href="<?php echo esc_url(home_url('/client-dashboard/')); ?>" class="rounded-2xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition">
            <div class="text-xs text-slate-400">My Bookings</div>
            <div class="text-lg text-white font-semibold mt-2">View activity</div>
          </a>
        </div>

        <div class="mb-10 gt-dashboard-section" id="gt-client-snapshot">
          <?php echo do_shortcode('[gigtune_client_home_snapshot]'); ?>
        </div>

        <div class="mb-10 gt-dashboard-section" id="gt-client-post-applicants">
          <h2 class="text-lg font-semibold text-white mb-4">Open Post Applicants</h2>
          <?php echo do_shortcode('[gigtune_client_psa_applicants_panel limit="6" show_empty="1"]'); ?>
        </div>

        <div class="grid gap-6 lg:grid-cols-2 gt-dashboard-grid">
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6 gt-dashboard-section" id="gt-client-dashboard">
            <h2 class="text-lg font-semibold text-white mb-4">Booking Snapshot</h2>
            <?php echo do_shortcode('[gigtune_client_dashboard]'); ?>
          </div>
          <div class="rounded-2xl border border-white/10 bg-white/5 p-6 gt-dashboard-section" id="gt-client-featured">
            <h2 class="text-lg font-semibold text-white mb-4">Featured Artists</h2>
            <?php echo do_shortcode('[gigtune_featured_artists_simple limit="6"]'); ?>
          </div>
        </div>

        <div class="mt-6 gt-dashboard-section gt-mobile-only" id="gt-client-profile">
          <h2 class="text-lg font-semibold text-white mb-4">Profile management</h2>
          <?php echo do_shortcode('[gigtune_client_profile_panel]'); ?>
        </div>
      <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>
</main>

<script>
(function() {
  var layout = document.querySelector('.gt-dashboard-layout');
  if (!layout) return;
  var toggle = layout.querySelector('.gt-dashboard-toggle');
  var drawer = layout.querySelector('.gt-dashboard-drawer');
  var backdrop = layout.querySelector('.gt-dashboard-backdrop');
  var sections = Array.prototype.slice.call(layout.querySelectorAll('.gt-dashboard-section'));
  if (!toggle || !drawer || !backdrop || !sections.length) return;

  function isMobile() {
    return window.matchMedia && window.matchMedia('(max-width: 1023px)').matches;
  }

  function setOpen(isOpen) {
    layout.classList.toggle('gt-dashboard-open', isOpen);
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  function showSection(targetId) {
    if (!isMobile()) {
      sections.forEach(function(section) {
        section.classList.remove('is-active');
      });
      return;
    }
    var found = false;
    sections.forEach(function(section) {
      var match = section.id === targetId;
      section.classList.toggle('is-active', match);
      if (match) found = true;
    });
    if (!found && sections.length) {
      sections[0].classList.add('is-active');
    }
  }

  function initMobileView() {
    if (isMobile()) {
      var hash = window.location.hash.replace('#', '');
      showSection(hash || sections[0].id);
    } else {
      sections.forEach(function(section) {
        section.classList.remove('is-active');
      });
    }
  }

  toggle.addEventListener('click', function() {
    setOpen(!layout.classList.contains('gt-dashboard-open'));
  });

  backdrop.addEventListener('click', function() {
    setOpen(false);
  });

  drawer.addEventListener('click', function(e) {
    if (e.target && e.target.tagName === 'A') {
      var href = e.target.getAttribute('href') || '';
      if (href.indexOf('#') === 0 && isMobile()) {
        e.preventDefault();
        var targetId = href.replace('#', '');
        showSection(targetId);
        if (history && history.replaceState) {
          history.replaceState(null, '', href);
        } else {
          window.location.hash = href;
        }
      }
      setOpen(false);
    }
  });

  window.addEventListener('resize', initMobileView);
  initMobileView();
})();
</script>

<?php get_footer(); ?>
