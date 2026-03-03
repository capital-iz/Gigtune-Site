<?php
/**
 * Privacy Policy page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Privacy Policy</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">How GigTune collects, uses, stores, and protects your information.</p>
      <p class="mt-4 text-sm text-slate-400">
        <strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?>
        <span class="mx-2">|</span>
        <strong>Version:</strong> 1.1
      </p>
      <div class="mt-6 rounded-xl border border-white/10 bg-black/20 p-4">
        <p class="text-sm font-semibold text-slate-200">Contents</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3 text-xs">
          <a href="#privacy-intro" class="text-blue-300 hover:text-blue-200">1. Introduction</a>
          <a href="#privacy-info-collect" class="text-blue-300 hover:text-blue-200">2. Information We Collect</a>
          <a href="#privacy-use" class="text-blue-300 hover:text-blue-200">3. How We Use Your Information</a>
          <a href="#privacy-storage" class="text-blue-300 hover:text-blue-200">4. Data Storage</a>
          <a href="#privacy-retention" class="text-blue-300 hover:text-blue-200">5. Data Retention</a>
          <a href="#privacy-cookies" class="text-blue-300 hover:text-blue-200">6. Cookies</a>
          <a href="#privacy-sharing" class="text-blue-300 hover:text-blue-200">7. Data Sharing</a>
          <a href="#privacy-rights" class="text-blue-300 hover:text-blue-200">8. Your Rights</a>
          <a href="#privacy-security" class="text-blue-300 hover:text-blue-200">9. Security</a>
          <a href="#privacy-changes" class="text-blue-300 hover:text-blue-200">10. Changes to This Policy</a>
          <a href="#privacy-kyc-data" class="text-blue-300 hover:text-blue-200">11. Identity Verification (Know Your Customer Compliance) Data</a>
          <a href="#privacy-special-category" class="text-blue-300 hover:text-blue-200">12. Special Category Data</a>
          <a href="#privacy-kyc-retention" class="text-blue-300 hover:text-blue-200">13. Retention</a>
          <a href="#privacy-safeguards" class="text-blue-300 hover:text-blue-200">14. Security Safeguards</a>
          <a href="#privacy-breach" class="text-blue-300 hover:text-blue-200">15. Breach Notification</a>
          <a href="#privacy-cross-border" class="text-blue-300 hover:text-blue-200">16. Cross-border Transfers</a>
        </div>
      </div>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <div class="prose prose-invert max-w-none prose-headings:text-white prose-p:text-slate-200 prose-li:text-slate-200 gt-policy-prose">
        <h2>GIGTUNE PRIVACY POLICY</h2>
        <p><strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?><br><strong>Version:</strong> 1.1</p>

        <h2 id="privacy-intro">1. Introduction</h2>
        <p>This Privacy Policy explains how GigTune (&ldquo;GigTune&rdquo;, &ldquo;we&rdquo;, &ldquo;us&rdquo;, &ldquo;our&rdquo;) collects, uses, stores, and protects your personal information when you use the GigTune platform.</p>
        <p>By using GigTune, you agree to the practices described in this policy.</p>

        <h2 id="privacy-info-collect">2. Information We Collect</h2>
        <h3 id="privacy-account-info">2.1 Account Information</h3>
        <p>When you create an account, we may collect:</p>
        <ul>
          <li>Full name</li>
          <li>Email address</li>
          <li>Username</li>
          <li>Account role (Client or Artist)</li>
        </ul>
        <p>This information is stored in the WordPress database used by the GigTune platform.</p>

        <h3 id="privacy-profile-info">2.2 Profile Information (Artists)</h3>
        <p>Artists may upload:</p>
        <ul>
          <li>Profile photo</li>
          <li>Profile banner image</li>
          <li>Bio and performance details</li>
          <li>Demo videos or media</li>
          <li>Availability data</li>
        </ul>
        <p>These uploads are stored in the WordPress media library and linked to your account.</p>

        <h3 id="privacy-booking-info">2.3 Booking Information</h3>
        <p>When bookings are created, we store:</p>
        <ul>
          <li>Event date and time</li>
          <li>Event location details</li>
          <li>Budget or pricing</li>
          <li>Booking status</li>
          <li>Dispute and refund status</li>
          <li>Booking history</li>
          <li>Admin review actions</li>
        </ul>
        <p>This information is stored in the WordPress database as post data and metadata.</p>

        <h3 id="privacy-messaging-data">2.4 Messaging Data</h3>
        <p>GigTune may store messages exchanged between Clients and Artists for:</p>
        <ul>
          <li>Booking coordination</li>
          <li>Dispute resolution</li>
          <li>Platform safety and moderation</li>
        </ul>
        <p>Messages are stored in the WordPress database and may be reviewed during disputes or investigations.</p>

        <h3 id="privacy-payment-info">2.5 Payment Information</h3>
        <p><strong>Manual Payments</strong></p>
        <p>If you use manual payment (e.g. bank transfer), we may store:</p>
        <ul>
          <li>Payment reference numbers</li>
          <li>Confirmation status</li>
          <li>Payment timestamps</li>
        </ul>
        <p>GigTune does not store your bank account login credentials.</p>
        <p><strong>Card Payments (YOCO)</strong></p>
        <p>If you pay via card:</p>
        <ul>
          <li>Payment processing is handled by a third-party payment provider (e.g. YOCO).</li>
          <li>GigTune does not store your full card details.</li>
          <li>We may store transaction identifiers and payment confirmation status returned by the provider.</li>
        </ul>
        <p>Your use of card payments is also subject to the privacy policies of the payment provider.</p>

        <h3 id="privacy-technical-usage">2.6 Technical &amp; Usage Data</h3>
        <p>We may collect:</p>
        <ul>
          <li>IP address</li>
          <li>Browser type</li>
          <li>Device information</li>
          <li>Login timestamps</li>
          <li>Session data</li>
        </ul>
        <p>This data helps maintain platform security and integrity.</p>

        <h2 id="privacy-use">3. How We Use Your Information</h2>
        <p>We use your information to:</p>
        <ul>
          <li>Create and manage user accounts</li>
          <li>Facilitate bookings between Clients and Artists</li>
          <li>Hold and process payments</li>
          <li>Manage disputes and refunds</li>
          <li>Provide support</li>
          <li>Improve platform performance</li>
          <li>Detect and prevent fraud or abuse</li>
        </ul>
        <p>We do not sell your personal information.</p>

        <h2 id="privacy-storage">4. Data Storage</h2>
        <p>Your data is stored in:</p>
        <ul>
          <li>The WordPress database associated with GigTune</li>
          <li>The WordPress media library (for uploads)</li>
          <li>Secure third-party payment systems (for card transactions)</li>
        </ul>
        <p>We implement reasonable administrative and technical safeguards to protect data from unauthorised access.</p>

        <h2 id="privacy-retention">5. Data Retention</h2>
        <p>GigTune retains data for as long as necessary to:</p>
        <ul>
          <li>Provide services</li>
          <li>Comply with legal obligations</li>
          <li>Resolve disputes</li>
          <li>Enforce platform agreements</li>
        </ul>
        <p>Booking and payment records may be retained for accounting and legal compliance purposes.</p>
        <p>Users may request account deletion (see Section 8), but certain transactional records may be retained where legally required.</p>

        <h2 id="privacy-cookies">6. Cookies</h2>
        <p>GigTune may use cookies to:</p>
        <ul>
          <li>Maintain login sessions</li>
          <li>Improve user experience</li>
          <li>Enhance security</li>
          <li>Track basic analytics</li>
        </ul>
        <p>You may disable cookies in your browser settings, but some platform features may not function correctly.</p>

        <h2 id="privacy-sharing">7. Data Sharing</h2>
        <p>GigTune may share information:</p>
        <ul>
          <li>With payment providers (e.g. YOCO) for transaction processing</li>
          <li>With legal authorities where required by law</li>
          <li>To enforce platform rules and agreements</li>
        </ul>
        <p>We do not sell or rent personal data to third parties.</p>

        <h2 id="privacy-rights">8. Your Rights</h2>
        <p>You may:</p>
        <ul>
          <li>Request access to your personal data</li>
          <li>Request correction of inaccurate information</li>
          <li>Request account deletion</li>
          <li>Request removal of profile media</li>
        </ul>
        <p>Account deletion requests may be submitted through platform support channels.</p>
        <p>GigTune may retain certain information where legally required or necessary for dispute resolution.</p>

        <h2 id="privacy-security">9. Security</h2>
        <p>We take reasonable steps to protect your data. However, no online platform can guarantee absolute security.</p>
        <p>Users are responsible for maintaining the confidentiality of their account credentials.</p>

        <h2 id="privacy-changes">10. Changes to This Policy</h2>
        <p>GigTune may update this Privacy Policy from time to time.</p>
        <p>Continued use of the platform constitutes acceptance of any updates.</p>

        <h2 id="privacy-kyc-data">11. Identity Verification (Know Your Customer Compliance) Data</h2>
        <p>GigTune may collect identity verification data to reduce fraud, protect marketplace integrity, and support safer booking and payout operations.</p>
        <p>This may include legal name, masked identity number references, verification status, risk flags, review outcomes, and supporting documents submitted for verification.</p>
        <p>Access to Identity Verification (Know Your Customer Compliance) data is restricted to authorised administrators and reviewers who require access for operational or compliance purposes.</p>

        <h2 id="privacy-special-category">12. Special Category Data</h2>
        <p>GigTune does not intentionally collect special personal information unless strictly required for verification, fraud prevention, or legal obligations. Where such processing is required, GigTune applies enhanced safeguards and limited access controls.</p>

        <h2 id="privacy-kyc-retention">13. Retention</h2>
        <p>Identity Verification (Know Your Customer Compliance) records and verification evidence are retained only for as long as necessary for fraud prevention, dispute handling, legal obligations, and audit requirements, and are deleted or minimised when no longer required.</p>

        <h2 id="privacy-safeguards">14. Security Safeguards</h2>
        <p>GigTune applies layered safeguards including access controls, security logging, rate limiting, monitoring, and secure storage controls for sensitive records.</p>
        <p>Document access is restricted and audited. Sensitive identifiers are masked and hashed where practical.</p>

        <h2 id="privacy-breach">15. Breach Notification</h2>
        <p>Where required by law, GigTune follows a POPIA-aligned breach notification approach and notifies affected parties and the Information Regulator as soon as reasonably possible, subject to lawful delay conditions.</p>

        <h2 id="privacy-cross-border">16. Cross-border Transfers</h2>
        <p>Where personal information is transferred across borders, GigTune applies POPIA Section 72 principles and appropriate safeguards, including lawful transfer grounds and contractual or technical protections.</p>
      </div>
    </section>
  </div>
</main>

<?php get_footer(); ?>
