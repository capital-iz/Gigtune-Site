<?php
/**
 * Terms & Conditions page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Terms &amp; Conditions</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">How bookings, payments, disputes and refunds work on GigTune.</p>
      <p class="mt-4 text-sm text-slate-400">
        <strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?>
        <span class="mx-2">|</span>
        <strong>Version:</strong> 1.1
      </p>

      <div class="mt-6 rounded-xl border border-white/10 bg-black/20 p-4">
        <p class="text-sm font-semibold text-slate-200">Contents</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3 text-xs">
          <a href="#intro" class="text-blue-300 hover:text-blue-200">Introduction</a>
          <a href="#platform-nature" class="text-blue-300 hover:text-blue-200">Platform Nature</a>
          <a href="#accounts" class="text-blue-300 hover:text-blue-200">Accounts</a>
          <a href="#bookings" class="text-blue-300 hover:text-blue-200">Bookings</a>
          <a href="#payments-temporary-holding" class="text-blue-300 hover:text-blue-200">Payments &amp; Temporary Holding</a>
          <a href="#service-fees" class="text-blue-300 hover:text-blue-200">Service Fees</a>
          <a href="#completion-disputes-refunds" class="text-blue-300 hover:text-blue-200">Completion, Disputes &amp; Refunds</a>
          <a href="#chargebacks" class="text-blue-300 hover:text-blue-200">Chargebacks</a>
          <a href="#user-conduct" class="text-blue-300 hover:text-blue-200">User Conduct</a>
          <a href="#intellectual-property" class="text-blue-300 hover:text-blue-200">Intellectual Property</a>
          <a href="#limitation-liability" class="text-blue-300 hover:text-blue-200">Limitation of Liability</a>
          <a href="#indemnification" class="text-blue-300 hover:text-blue-200">Indemnification</a>
          <a href="#suspension-termination" class="text-blue-300 hover:text-blue-200">Suspension &amp; Termination</a>
          <a href="#force-majeure" class="text-blue-300 hover:text-blue-200">Force Majeure</a>
          <a href="#changes-terms" class="text-blue-300 hover:text-blue-200">Changes to Terms</a>
          <a href="#governing-law" class="text-blue-300 hover:text-blue-200">Governing Law</a>
          <a href="#platform-safety-verification" class="text-blue-300 hover:text-blue-200">Platform Safety &amp; Verification</a>
          <a href="#suspicious-activity" class="text-blue-300 hover:text-blue-200">Suspicious Activity</a>
        </div>
      </div>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <div class="prose prose-invert max-w-none prose-headings:text-white prose-p:text-slate-200 prose-li:text-slate-200 gt-policy-prose">
        <h2 id="intro">1. Introduction</h2>
        <p>These Terms and Conditions (&ldquo;Terms&rdquo;) govern your access to and use of the GigTune platform (&ldquo;GigTune&rdquo;, &ldquo;Platform&rdquo;, &ldquo;we&rdquo;, &ldquo;us&rdquo;, or &ldquo;our&rdquo;).</p>
        <p>By creating an account, accessing, or using GigTune, you confirm that you have read, understood, and agreed to these Terms. If you do not agree, you must not use the Platform.</p>
        <p>These Terms form a legally binding agreement between you and GigTune.</p>

        <h2 id="platform-nature">2. Platform Nature</h2>
        <p>GigTune is a digital booking facilitation platform that connects independent artists (&ldquo;Artists&rdquo;) with clients seeking performance services (&ldquo;Clients&rdquo;).</p>
        <p>GigTune is not the employer, partner, or agent of any Artist. GigTune does not supervise, control, or direct the manner in which Artists perform services. GigTune does not guarantee the quality, legality, or outcome of any booking.</p>
        <p>GigTune acts solely as: (1) a booking intermediary, (2) a payment facilitator, and (3) a temporary holder of funds.</p>
        <p>All performance services are agreements directly between the Client and the Artist.</p>

        <h2 id="accounts">3. User Accounts</h2>
        <p>To use certain features, you must create an account. You agree to provide accurate information, keep your login credentials secure, and accept responsibility for all activity under your account.</p>
        <p>GigTune may suspend or terminate accounts containing false information, abusive behaviour, or violations of these Terms.</p>

        <h2 id="bookings">4. Booking Process</h2>
        <p>When a Client submits a booking request:</p>
        <ul>
          <li>a booking record is created in the GigTune system;</li>
          <li>the Artist may accept or decline the booking; and</li>
          <li>a booking is considered confirmed only when payment is successfully processed and recorded by GigTune.</li>
        </ul>
        <p>GigTune does not guarantee that a booking request will be accepted.</p>

        <h2 id="payments-temporary-holding">5. Payments &amp; Temporary Holding</h2>
        <h3>5.1 Payment Methods</h3>
        <p>GigTune may support manual payments (e.g. bank transfer) and card payments via third-party providers (e.g. YOCO). GigTune does not store full card details.</p>
        <h3>5.2 Temporary Holding</h3>
        <p>Once payment is confirmed, funds are held by GigTune in Temporary Holding and are not immediately released to the Artist.</p>
        <p>Funds become eligible for payout only when: the booking is marked as completed; no dispute is active; no refund request is pending; and any required administrative review is complete.</p>
        <p>GigTune reserves the right to delay or withhold payouts in cases involving disputes, refund reviews, suspected fraud, payment reversals/chargebacks, or policy violations.</p>

        <h2 id="service-fees">6. Service Fees</h2>
        <p>GigTune charges service fees for facilitating bookings and operating the platform. Service fees may be deducted before payout. Service fees may be non-refundable once payment has been confirmed. Fees cover operational, technical, and administrative costs.</p>
        <p>GigTune reserves the right to modify service fees with notice.</p>

        <h2 id="completion-disputes-refunds">7. Completion, Disputes &amp; Refunds</h2>
        <h3>7.1 Completion</h3>
        <p>After performance of services, the Artist may mark the booking as completed and the Client may confirm completion where applicable. Payout is processed subject to platform rules.</p>
        <h3>7.2 Disputes</h3>
        <p>Either party may initiate a dispute within the permitted timeframe. While a dispute is active, the booking may be locked, funds will not be released, and refund requests may be paused.</p>
        <p>GigTune may review communications and booking data, request evidence, mediate disputes, and make final determinations regarding payouts or refunds. GigTune&rsquo;s decision is final.</p>
        <h3>7.3 Refunds</h3>
        <p>Refund requests are not automatic and are subject to review. Refund status may include REQUESTED, PENDING, FAILED, SUCCEEDED. Refund eligibility may depend on booking status, evidence, platform rules, and abuse detection. GigTune may deny refund requests deemed fraudulent, abusive, or unsupported.</p>

        <h2 id="chargebacks">8. Chargebacks</h2>
        <p>If a Client initiates a chargeback through a payment provider, GigTune may suspend the associated account, withhold payouts during investigation, and request supporting documentation. Abusive chargebacks may result in permanent account termination.</p>

        <h2 id="user-conduct">9. User Conduct</h2>
        <p>Users may not bypass GigTune&rsquo;s payment system for bookings initiated on the Platform, manipulate booking/payment states, harass other users, submit false disputes/refund claims, or manipulate ratings/reviews. Violations may result in suspension or removal.</p>

        <h2 id="intellectual-property">10. Intellectual Property</h2>
        <p>Users retain ownership of content they upload but grant GigTune a non-exclusive licence to display and use such content for platform functionality and promotional purposes. Users must not upload content they do not have rights to use.</p>

        <h2 id="limitation-liability">11. Limitation of Liability</h2>
        <p>GigTune is not liable for service performance quality, event outcomes, indirect or consequential damages, or loss of profits/business interruption. To the maximum extent permitted by law, GigTune&rsquo;s total liability shall not exceed the total service fee collected for the booking in dispute.</p>

        <h2 id="indemnification">12. Indemnification</h2>
        <p>You agree to indemnify and hold GigTune harmless from claims, losses, damages, liabilities, and expenses arising from your violation of these Terms, misuse of the Platform, or disputes between Clients and Artists.</p>

        <h2 id="suspension-termination">13. Suspension &amp; Termination</h2>
        <p>GigTune may suspend, restrict, or terminate accounts for fraud, payment manipulation, abuse of disputes/refunds, harassment, or any violation of these Terms. GigTune may retain funds during investigation.</p>

        <h2 id="force-majeure">14. Force Majeure</h2>
        <p>GigTune is not liable for delays or failures caused by events beyond its reasonable control, including technical failures, payment provider outages, natural disasters, or governmental actions.</p>

        <h2 id="changes-terms">15. Changes to Terms</h2>
        <p>GigTune may update these Terms at any time. Continued use of the Platform after updates constitutes acceptance of revised Terms.</p>

        <h2 id="governing-law">16. Governing Law</h2>
        <p>These Terms are governed by and interpreted in accordance with the laws of the jurisdiction in which GigTune operates.</p>

        <h2 id="platform-safety-verification">17. Platform Safety &amp; Verification</h2>
        <p>GigTune may require identity verification, profile completion, and policy acceptance before enabling bookings, payments, request acceptance, or payouts.</p>
        <p>GigTune may temporarily restrict, pause, or freeze account activity and payout activity while verification or risk review is in progress.</p>
        <p>GigTune may request additional verification documentation for higher-risk activity, suspicious account behavior, or payout-protection checks.</p>

        <h2 id="suspicious-activity">18. Suspicious Activity</h2>
        <p>GigTune may investigate suspicious behavior, including possible fraud, impersonation, payment abuse, chargeback abuse, or coordinated manipulation of disputes/refunds.</p>
        <p>GigTune may lock bookings, hold funds, restrict accounts, and where legally required or appropriate, share information with payment providers, legal counsel, or relevant authorities.</p>
      </div>
    </section>
  </div>
</main>

<?php get_footer(); ?>
