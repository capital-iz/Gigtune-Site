<?php
/**
 * Cookie Policy page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Cookie Policy</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">How GigTune uses cookies and similar technologies.</p>
      <p class="mt-4 text-sm text-slate-400">
        <strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?>
        <span class="mx-2">|</span>
        <strong>Version:</strong> 1.1
      </p>

      <div class="mt-6 rounded-xl border border-white/10 bg-black/20 p-4">
        <p class="text-sm font-semibold text-slate-200">Contents</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2 text-xs">
          <a href="#intro" class="text-blue-300 hover:text-blue-200">Introduction</a>
          <a href="#what-are-cookies" class="text-blue-300 hover:text-blue-200">What are cookies?</a>
          <a href="#cookies-we-use" class="text-blue-300 hover:text-blue-200">Cookies GigTune uses</a>
          <a href="#managing-cookies" class="text-blue-300 hover:text-blue-200">Managing cookies</a>
          <a href="#policy-changes" class="text-blue-300 hover:text-blue-200">Changes to this Cookie Policy</a>
          <a href="#security-cookies" class="text-blue-300 hover:text-blue-200">Security cookies</a>
        </div>
      </div>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <div class="prose prose-invert max-w-none prose-headings:text-white prose-p:text-slate-200 prose-li:text-slate-200 gt-policy-prose">
        <h2>GIGTUNE COOKIE POLICY</h2>
        <p><strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?><br><strong>Version:</strong> 1.1</p>

        <h2 id="intro">1. Introduction</h2>
        <p>This Cookie Policy explains how GigTune (&ldquo;GigTune&rdquo;, &ldquo;we&rdquo;, &ldquo;us&rdquo;, or &ldquo;our&rdquo;) uses cookies and similar technologies when you access or use the GigTune platform.</p>

        <h2 id="what-are-cookies">2. What are cookies?</h2>
        <p>Cookies are small text files stored on your device when you visit a website. They help websites remember information about your session, preferences, and security settings. Cookies may be session-based (deleted when you close your browser) or persistent (stored until they expire or are deleted).</p>

        <h2 id="cookies-we-use">3. Cookies GigTune uses</h2>
        <h3>3.1 Essential cookies (GigTune proprietary software / GT &copy; core)</h3>
        <p>GigTune proprietary software (GT &copy;) uses essential cookies to:</p>
        <ul>
          <li>Keep users logged in and authenticated</li>
          <li>Protect secure areas of the site (including account areas and administration)</li>
          <li>Support basic site functionality and security</li>
        </ul>
        <p>Examples may include (names vary):</p>
        <ul>
          <li><code>gt_session_*</code></li>
          <li><code>gt_secure_auth_*</code></li>
          <li><code>gt_settings_*</code></li>
          <li><code>gt_settings_time_*</code></li>
        </ul>
        <p>These cookies are required for the platform to function correctly.</p>

        <h3>3.2 Security &amp; integrity cookies</h3>
        <p>GigTune may use cookies or similar mechanisms to help:</p>
        <ul>
          <li>Prevent abuse and suspicious activity</li>
          <li>Maintain platform integrity</li>
          <li>Improve security during authentication and account use</li>
        </ul>
        <p>These are treated as essential where they are required to keep the platform safe.</p>

        <h3>3.3 Preference / consent cookies</h3>
        <p>If you use GigTune&rsquo;s cookie banner, we store your choice so we don&rsquo;t ask you repeatedly. For example:</p>
        <ul>
          <li><code>gt_cookie_consent</code> (values such as <code>accept</code> or <code>reject</code>)</li>
        </ul>

        <h3>3.4 Payment provider cookies (when paying by card)</h3>
        <p>If you pay by card through a third-party provider (for example YOCO), that provider may use cookies during the payment flow to:</p>
        <ul>
          <li>Secure and process transactions</li>
          <li>Detect fraud</li>
          <li>Ensure the checkout experience works correctly</li>
        </ul>
        <p>GigTune does not control third-party cookies. We recommend reviewing the payment provider&rsquo;s privacy/cookie policy when using card checkout.</p>

        <h3>3.5 Analytics cookies (Site Kit by Google / Google Analytics)</h3>
        <p>GigTune uses Site Kit by Google to help us understand how visitors use the platform and to improve performance, user experience, and reliability.</p>
        <p>These analytics tools may set cookies and collect usage information such as:</p>
        <ul>
          <li>pages visited and time spent on pages</li>
          <li>device and browser type</li>
          <li>approximate location (derived from IP address)</li>
          <li>referral source (how you arrived on GigTune)</li>
          <li>interaction events (such as clicks and navigation patterns)</li>
        </ul>
        <p>GigTune does not use these analytics cookies to store your payment details. Analytics data is used to measure and improve the platform.</p>
        <p>Cookies set by Google services may include (examples, names may vary):</p>
        <ul>
          <li><code>_ga</code>, <code>_ga_*</code> (Google Analytics identifiers)</li>
          <li><code>_gid</code> (user/session distinction)</li>
          <li><code>_gat</code> (rate limiting)</li>
        </ul>
        <p>For more information on how Google uses data, users can review Google&rsquo;s policies via the Site Kit/Google documentation.</p>
        <p>If you also show a cookie banner with optional controls, the banner must treat analytics cookies as optional and respect the user&rsquo;s choice where required.</p>

        <h2 id="managing-cookies">4. Managing cookies</h2>
        <p>You can control cookies in several ways:</p>
        <ul>
          <li>Use your browser settings to block or delete cookies</li>
          <li>Use GigTune&rsquo;s cookie banner (where available) to accept or reject optional cookies</li>
        </ul>
        <p>Please note: blocking essential cookies may prevent certain GigTune features from working properly (such as logging in).</p>

        <h2 id="policy-changes">5. Changes to this Cookie Policy</h2>
        <p>We may update this Cookie Policy from time to time. Continued use of the platform after changes means you accept the updated policy.</p>

        <h2 id="security-cookies">6. Security cookies</h2>
        <p>GigTune may use security cookies and related mechanisms to support rate-limiting controls, anti-abuse detection, session integrity checks, and protection against suspicious traffic patterns.</p>
        <p>These controls help secure account access and reduce fraud risk across bookings, payments, and messaging flows.</p>
      </div>
    </section>
  </div>
</main>

<?php get_footer(); ?>
