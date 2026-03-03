<?php
/**
 * Acceptable Use Policy page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Acceptable Use Policy</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">Rules for fair and professional use of GigTune.</p>
      <p class="mt-4 text-sm text-slate-400">
        <strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?>
        <span class="mx-2">|</span>
        <strong>Version:</strong> 1.1
      </p>
      <div class="mt-6 rounded-xl border border-white/10 bg-black/20 p-4">
        <p class="text-sm font-semibold text-slate-200">Contents</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3 text-xs">
          <a href="#aup-1" class="text-blue-300 hover:text-blue-200">1. Purpose</a>
          <a href="#aup-2" class="text-blue-300 hover:text-blue-200">2. Core Principle</a>
          <a href="#aup-3" class="text-blue-300 hover:text-blue-200">3. Prohibited Conduct</a>
          <a href="#aup-4" class="text-blue-300 hover:text-blue-200">4. Enforcement Rights</a>
          <a href="#aup-5" class="text-blue-300 hover:text-blue-200">5. Reporting Violations</a>
          <a href="#aup-6" class="text-blue-300 hover:text-blue-200">6. Consequences of Violation</a>
          <a href="#aup-7" class="text-blue-300 hover:text-blue-200">7. Identity Verification (Know Your Customer Compliance) Evasion &amp; Fraud</a>
        </div>
      </div>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <div class="prose prose-invert max-w-none prose-headings:text-white prose-p:text-slate-200 prose-li:text-slate-200 gt-policy-prose">
        <h2>GigTune Acceptable Use Policy</h2>
        <p><strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?><br><strong>Version:</strong> 1.1</p>

        <h2 id="aup-1">1. Purpose</h2>
        <p>This Acceptable Use Policy (&ldquo;AUP&rdquo;) sets out the rules governing how users may interact with the GigTune platform (&ldquo;GigTune&rdquo;, &ldquo;Platform&rdquo;).</p>
        <p>GigTune operates a Temporary Holding-based booking system. To protect Clients and Artists, all users must follow these rules to ensure fairness, safety, and integrity.</p>
        <p>By using GigTune, you agree to comply with this policy.</p>

        <h2 id="aup-2">2. Core Principle</h2>
        <p>GigTune exists to facilitate legitimate bookings between Clients and Artists. Any behaviour that manipulates bookings, payments, disputes, refunds, ratings, or messaging systems undermines the integrity of the Platform and is strictly prohibited.</p>

        <h2 id="aup-3">3. Prohibited Conduct</h2>

        <h3>3.1 Fake or Fraudulent Bookings</h3>
        <p>Users may not:</p>
        <ul>
          <li>Create booking requests with no genuine intent to complete the event.</li>
          <li>Accept bookings without intending to perform.</li>
          <li>Mark bookings as completed when services were not delivered.</li>
          <li>Fabricate disputes or submit false evidence.</li>
          <li>Use bookings to simulate financial transactions.</li>
        </ul>
        <p>GigTune reserves the right to suspend accounts involved in fraudulent activity and may report such activity where required by law.</p>

        <h3>3.2 Payment Bypass &amp; Fee Evasion</h3>
        <p>GigTune operates a Temporary Holding system to protect both Clients and Artists.</p>
        <p>Users may not:</p>
        <ul>
          <li>Attempt to bypass GigTune&rsquo;s payment system by requesting direct payments outside the platform for bookings initiated through GigTune.</li>
          <li>Share bank details, contact details, or alternative payment methods for the purpose of avoiding platform service fees.</li>
          <li>Complete transactions off-platform after first connecting through GigTune.</li>
        </ul>
        <p>Violations may result in:</p>
        <ul>
          <li>Immediate account suspension</li>
          <li>Permanent account termination</li>
          <li>Withholding of pending payouts</li>
          <li>Restriction from future bookings</li>
        </ul>

        <h3>3.3 Abuse of the Dispute or Refund System</h3>
        <p>Users may not:</p>
        <ul>
          <li>Open disputes without reasonable cause.</li>
          <li>Repeatedly request refunds without valid evidence.</li>
          <li>Use disputes to delay payout unfairly.</li>
          <li>Submit misleading or fabricated documentation.</li>
        </ul>
        <p>GigTune monitors patterns of abuse and may restrict or permanently remove users who misuse these systems.</p>

        <h3>3.4 Harassment &amp; Messaging Abuse</h3>
        <p>GigTune provides messaging tools to coordinate bookings professionally.</p>
        <p>Users may not:</p>
        <ul>
          <li>Harass, threaten, intimidate, or insult other users.</li>
          <li>Send discriminatory or abusive messages.</li>
          <li>Spam users with unsolicited promotions.</li>
          <li>Use messaging tools for non-booking-related marketing.</li>
        </ul>
        <p>Messages may be reviewed during disputes or abuse investigations.</p>

        <h3>3.5 Demo Content &amp; Media Abuse</h3>
        <p>Artists may upload demo videos, profile photos, and banner images.</p>
        <p>Users may not:</p>
        <ul>
          <li>Upload content they do not have the rights to use.</li>
          <li>Upload copyrighted material without permission.</li>
          <li>Upload explicit, illegal, or harmful content.</li>
          <li>Misrepresent another person&rsquo;s performance as their own.</li>
          <li>Spam excessive or irrelevant media uploads.</li>
        </ul>
        <p>GigTune may remove content that violates this policy without prior notice.</p>

        <h3>3.6 Ratings &amp; Review Manipulation</h3>
        <p>If GigTune provides rating functionality, users may not:</p>
        <ul>
          <li>Submit fake or coordinated reviews.</li>
          <li>Use multiple accounts to manipulate ratings.</li>
          <li>Threaten negative reviews to force concessions.</li>
          <li>Offer incentives in exchange for positive ratings.</li>
        </ul>
        <p>GigTune may remove manipulated ratings and suspend involved accounts.</p>

        <h3>3.7 Open Posts Misuse</h3>
        <p>Clients creating open posts must:</p>
        <ul>
          <li>Provide accurate event details.</li>
          <li>Avoid posting misleading or fake opportunities.</li>
          <li>Not use open posts to solicit unpaid labour.</li>
        </ul>
        <p>Artists applying to open posts must:</p>
        <ul>
          <li>Apply genuinely and professionally.</li>
          <li>Avoid mass-spam applications.</li>
          <li>Provide truthful profile information.</li>
        </ul>

        <h3>3.8 Security Violations</h3>
        <p>Users may not:</p>
        <ul>
          <li>Attempt to access accounts not belonging to them.</li>
          <li>Exploit vulnerabilities in the Platform.</li>
          <li>Interfere with system performance.</li>
          <li>Use bots or automation to manipulate bookings, disputes, or ratings.</li>
        </ul>

        <h2 id="aup-4">4. Enforcement Rights</h2>
        <p>GigTune reserves the right to:</p>
        <ul>
          <li>Lock bookings during investigations.</li>
          <li>Pause payouts during review.</li>
          <li>Remove content.</li>
          <li>Suspend or terminate accounts.</li>
          <li>Withhold funds pending investigation.</li>
        </ul>
        <p>Enforcement decisions are made at GigTune&rsquo;s sole discretion to protect platform integrity.</p>

        <h2 id="aup-5">5. Reporting Violations</h2>
        <p>Users may report violations via:</p>
        <ul>
          <li>The dispute system</li>
          <li>Support channels</li>
          <li>Administrative contact</li>
        </ul>
        <p>False or malicious reporting may itself be considered a violation.</p>

        <h2 id="aup-6">6. Consequences of Violation</h2>
        <p>Violations of this policy may result in:</p>
        <ul>
          <li>Warning notices</li>
          <li>Temporary suspension</li>
          <li>Permanent termination</li>
          <li>Loss of platform privileges</li>
          <li>Legal action where appropriate</li>
        </ul>
        <p>GigTune&rsquo;s priority is maintaining a secure, professional booking environment.</p>

        <h2 id="aup-7">7. Identity Verification (Know Your Customer Compliance) Evasion &amp; Fraud</h2>
        <p>Users may not submit fake identity documents, impersonate another person, conceal material identity information, or attempt to bypass verification controls.</p>
        <p>GigTune may suspend or lock accounts, hold payouts, and where required or appropriate, report suspected fraud to relevant providers or authorities.</p>
      </div>
    </section>
  </div>
</main>

<?php get_footer(); ?>
