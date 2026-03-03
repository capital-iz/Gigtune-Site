<?php
/**
 * Refund Policy page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Refund Policy</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">How refunds are reviewed and processed on GigTune.</p>
      <p class="mt-4 text-sm text-slate-400">
        <strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?>
        <span class="mx-2">|</span>
        <strong>Version:</strong> 1.1
      </p>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <div class="prose prose-invert max-w-none prose-headings:text-white prose-p:text-slate-200 prose-li:text-slate-200 gt-policy-prose">
        <h2>GIGTUNE REFUND POLICY</h2>
        <p><strong>Last Updated:</strong> <?php echo esc_html(date('Y-m-d')); ?><br><strong>Version:</strong> 1.1</p>

        <h2>1. Purpose</h2>
        <p>This Refund Policy explains how refunds work on GigTune. Because GigTune facilitates bookings and holds funds in Temporary Holding, refunds are handled through a structured review process to protect both Clients and Artists.</p>
        <p>By using GigTune, you agree to this Refund Policy.</p>

        <h2>2. Key Principle: Refunds Are Not Automatic</h2>
        <p>Refunds on GigTune are not automatic.</p>
        <p>All refund requests are reviewed by GigTune Admin and may be approved, denied, or require additional evidence before a decision is made.</p>

        <h2>3. What Happens When You Request a Refund</h2>
        <p>When a refund request is submitted:</p>
        <ul>
          <li>The booking may be locked while the refund is being processed.</li>
          <li>Payouts are paused and will not be processed until the refund request is resolved.</li>
          <li>GigTune Admin reviews the request and may request additional information.</li>
        </ul>
        <p>Refund status may include (for transparency inside the platform):</p>
        <ul>
          <li>REQUESTED (refund requested by user)</li>
          <li>PENDING (under admin review / processing)</li>
          <li>FAILED (refund attempt failed or requires action)</li>
          <li>SUCCEEDED (refund completed)</li>
        </ul>

        <h2>4. Refund Eligibility (What We Consider)</h2>
        <p>Refund eligibility may depend on:</p>
        <ul>
          <li>Booking status (e.g. pending, confirmed, completed)</li>
          <li>Evidence provided by the Client and/or Artist</li>
          <li>Whether the service was delivered as agreed</li>
          <li>Whether a dispute exists or has been resolved</li>
          <li>Whether the request indicates platform abuse or fraud</li>
        </ul>
        <p>GigTune may also deny refunds where:</p>
        <ul>
          <li>The request is clearly abusive or fraudulent</li>
          <li>The booking was completed and no valid evidence supports the claim</li>
          <li>The request is made outside permitted timelines (where applicable)</li>
        </ul>

        <h2>5. Service Fees</h2>
        <p>GigTune charges service fees for facilitating bookings and operating the platform.</p>
        <p>Service fees may be non-refundable once payment has been confirmed, even if a booking is refunded, unless GigTune decides otherwise in exceptional circumstances.</p>
        <p>Where a refund is granted, GigTune may refund:</p>
        <ul>
          <li>The booking amount in full or in part</li>
          <li>The service fee in full or in part (at GigTune&rsquo;s discretion)</li>
        </ul>

        <h2>6. Disputes and Refunds</h2>
        <p>Refund requests may overlap with disputes.</p>
        <p>If a dispute is open, GigTune may require dispute resolution before finalising a refund.</p>
        <p>While disputes/refunds are active, bookings may be locked and payouts paused.</p>
        <p>GigTune may request proof such as:</p>
        <ul>
          <li>Message history</li>
          <li>Payment confirmation / references</li>
          <li>Event details and changes</li>
          <li>Evidence of non-delivery or misrepresentation</li>
        </ul>

        <h2>7. Refund Timeframes</h2>
        <p>Refund processing times depend on:</p>
        <ul>
          <li>Admin review time</li>
          <li>Availability of evidence and responses</li>
          <li>Payment method used (manual transfer vs card)</li>
          <li>Third-party processing timelines</li>
        </ul>
        <p>Refunds typically take [X] business days to reflect once approved and processed.</p>
        <p>(Recommended default: 5&ndash;10 business days. You can pick the number you want and we lock it in.)</p>

        <h2>8. Chargebacks / Payment Reversals (Card Payments)</h2>
        <p>If card payments are used via a third-party provider (e.g. YOCO), chargebacks may be handled under provider rules.</p>
        <p>GigTune reserves the right to:</p>
        <ul>
          <li>Suspend accounts involved in chargeback abuse</li>
          <li>Withhold payouts during chargeback investigation</li>
          <li>Request additional verification</li>
        </ul>

        <h2>9. Abuse Prevention</h2>
        <p>GigTune does not tolerate refund abuse. Examples include:</p>
        <ul>
          <li>Repeated refund requests with no valid basis</li>
          <li>Attempts to force refunds through threats or harassment</li>
          <li>Creating bookings with no intent to honour them</li>
        </ul>
        <p>Abuse may result in account suspension or termination.</p>

        <h2>10. Contact</h2>
        <p>If you need help with a refund request, use the platform&rsquo;s support channels or dispute tools where available.</p>

        <h2>11. Fraud &amp; Chargeback Handling</h2>
        <p>GigTune may pause, defer, or place refund requests under extended review where fraud risk, identity verification concerns, chargeback exposure, or suspicious account activity is detected.</p>
        <p>During this process, bookings may remain temporarily restricted and payouts may remain on hold until verification or investigation is completed.</p>
      </div>

      <div class="mt-8">
        <a href="<?php echo esc_url(home_url('/how-it-works/')); ?>" class="gt-btn gt-btn-muted inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">
          Back to How It Works
        </a>
      </div>
    </section>
  </div>
</main>

<?php get_footer(); ?>
