<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
require_once __DIR__ . '/../includes/wedding-draft.php';
require_once __DIR__ . '/../includes/booking-draft-resume.php';
require_once __DIR__ . '/../includes/funeral-schedule.php';
require_once __DIR__ . '/../includes/paymongo.php';
$identity = requireParishionerOrGuest();

// Services is the only donation page. A donor back from PayMongo (donate.php's
// success_url or cancel_url) is handled here, then sent to the clean URL, where
// the Thank You (verified) or a one-time note shows; a refresh repeats nothing.
if (isset($_GET['paymongo_return']) || isset($_GET['paymongo_cancelled'])) {
  finishDonationReturn((int) ($_GET['appointment_id'] ?? 0), isset($_GET['paymongo_cancelled']));
}
$donationThankYou = pullDonationThankYou(); // set by a verified donation return just before this page loaded
$donationReturnNotice = $donationThankYou ? null : pullDonationReturnNotice();
// A parishioner's finished booking lands here (bookingReturnUrl()): its Thank
// You opens once. A donation Thank You goes first; the booking one then stays
// in the session for the next visit. Guests see theirs on Check Status.
$thankYouReference = null;
$thankYouBooking = null;
if (!$identity['is_guest'] && !$donationThankYou) {
  $thankYouReference = pullBookingThankYou();
  $thankYouBooking = parishionerBookingThankYou($thankYouReference, (int) $identity['parishioner_id']);
}

// "Back to Booking Form" from a draft's Supporting Documents step: reopen the
// form for that draft (access-checked) so re-submitting updates it in place.
$resumeDraft = null;
if (preg_match('/^(baptism|wedding|funeral)-(\d+)$/', (string) ($_GET['resume_draft'] ?? ''), $resumeMatch)) {
  $resumeDraft = loadResumableBookingDraft($resumeMatch[1], (int) $resumeMatch[2]);
}

ensureCertificateService();
$services = db()->query("SELECT * FROM services WHERE is_active = 1 AND category != 'Donation' ORDER BY category, service_name")->fetchAll();
$variableFeeCategories = ['Baptism', 'Wedding'];
$feeRulesByCategory = [];
try {
  $feeRuleRows = db()->query("SELECT * FROM service_fee_rules ORDER BY service_category, schedule_type, pss_classification")->fetchAll();
  foreach ($feeRuleRows as $feeRule)
    $feeRulesByCategory[$feeRule['service_category']][] = $feeRule;
} catch (Throwable $e) {
  // The pricing migration is deployed separately; legacy services remain usable until then.
}
$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';

// Donation modal (includes/donation-modal.php, shared with Ongoing Projects).
// Links open it directly: ?donate=1, or ?project_id=N (preselected only if
// that project is active; donate.php re-checks it).
$isGuest = $identity['is_guest'];
$accountName = '';
if (!$isGuest) {
  $stmt = db()->prepare('SELECT firstname, lastname FROM users WHERE user_id = ?');
  $stmt->execute([$identity['user_id']]);
  $u = $stmt->fetch();
  if ($u) $accountName = trim($u['firstname'] . ' ' . $u['lastname']);
}
$projects = db()->query('SELECT project_id, project_name FROM projects WHERE is_active = TRUE ORDER BY project_name')->fetchAll();
$preselectedProjectId = (int) ($_GET['project_id'] ?? 0) ?: null;
$autoOpenDonate = $donationEnabled && !$donationThankYou && !$thankYouReference && (isset($_GET['donate']) || $preselectedProjectId);

$priests =db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
$blockedRows = db()->query('SELECT calendar_date, notes FROM calendar WHERE is_blocked = 1')->fetchAll();
$calendarBlocked = array_map(fn($b) => ['date' => $b['calendar_date'], 'notes' => $b['notes']], $blockedRows);
$preselectedDate = $_GET['date'] ?? '';

// House Blessing (and any future Blessing-category service) asks for a
// contact number and the address to bless — plain text, never a document
// upload. A registered parishioner's own profile pre-fills these (still
// editable); a guest always enters them fresh.
$profilePhone = '';
$profileAddress = '';
if (!$identity['is_guest']) {
  $stmt = db()->prepare('SELECT phone, address FROM users WHERE user_id = ?');
  $stmt->execute([$identity['user_id']]);
  $profileRow = $stmt->fetch() ?: [];
  $profilePhone = $profileRow['phone'] ?? '';
  $profileAddress = $profileRow['address'] ?? '';
}

$policies = [];
$requirementsByService = [];
foreach ($services as $s) {
  $policies[$s['category']] = schedulingPolicyText($s['category'], (int) $s['service_id']);
  $requirementsByService[$s['service_id']] = $s['category'] === 'Wedding'
    ? weddingRequiredDocumentsFromCatalog($s['requirements'])
    : parseRequirementsList($s['requirements']);
}
foreach (['Mass Intention', 'Funeral', 'First Communion'] as $cat) {
  if (!isset($policies[$cat])) {
    $policies[$cat] = schedulingPolicyText($cat);
  }
}

// These 2 categories offer a Regular (parish-fixed slot) vs Special
// (custom date/time, availability-checked) choice — see includes/scheduling.php.
$scheduleToggleCategories = ['Baptism', 'Wedding'];

// Wedding Pre-Cana seminar (includes/precana.php): shown once its database
// columns exist. Regular sessions are calculated here (second Saturdays,
// 8:00 AM); the form only offers the ones before the chosen wedding date.
$precanaEnabled = precanaAvailable();
// Funeral preferred burial / Funeral Mass schedule (includes/funeral-schedule.php):
// shown once database/migration_funeral_preferred_burial.sql has been run.
$funeralScheduleEnabled = funeralPreferredScheduleAvailable();
$precanaSessions = $precanaEnabled ? precanaRegularSessions(null, 18) : [];

// 30-minute interval options for scheduling dropdowns (6:00 AM to 9:00 PM)
$halfHourSlots = [];
for ($m = 6 * 60; $m <= 21 * 60; $m += 30) {
    $h = (int) floor($m / 60);
    $min = $m % 60;
    $val = sprintf('%02d:%02d', $h, $min);
    $lbl = date('g:i A', strtotime("2000-01-01 $val:00"));
    $halfHourSlots[$val] = $lbl;
}

$active = 'services';
$pageTitle = 'Available Services';
$hidePageHeading = true; // the nav bar already names this page
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . ($identity['is_guest'] ? 'public-shell-start.php' : 'dash-start.php');
?>
<?php
// The Thank You dialogs come first, before the page content and its slow scripts
// (FullCalendar from a CDN, the booking form's code): each opens itself as soon
// as it is read, so after a booking or donation the dialog is already up when the
// page first appears, instead of popping in after everything else has loaded.
// Only one of them is ever shown (a donation Thank You goes first, see above).
include __DIR__ . '/../includes/donation-thank-you-modal.php'; // after a verified online donation, once
$thankYouIsGuest = false; include __DIR__ . '/../includes/thank-you-modal.php'; // after a parishioner's booking, once
?>

<?php if ($donationReturnNotice): // after a donation return without a Thank You (pending, failed, ...) ?>
  <div class="alert" role="status" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); margin-bottom:16px;">
    <?= in_array($donationReturnNotice['status'], ['failed', 'cancelled'], true) ? '⚠' : '⏳' ?> <?= e($donationReturnNotice['message']) ?>
  </div>
<?php endif; ?>

<?php if ($identity['is_guest']): ?>
  <div class="alert"
    style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); margin-bottom:18px;">
    You're browsing as a guest — no account needed to book. <a href="<?= url('auth/register.php') ?>">Create a free
      account</a> to track your requests in one place, or continue below and save your confirmation reference code.
  </div>
<?php endif; ?>

<?php if ($donationEnabled): ?>
  <div class="card"
    style="background: linear-gradient(135deg, var(--cream-dark), #faf0d0); padding: 22px 24px; margin-bottom: 24px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
    <div style="flex: 1 1 280px;">
      <h3 style="font-size: 20px; margin: 0 0 6px 0;">🤲 Donate to Our Parish</h3>
      <p style="font-size: 14.5px; color: var(--brown-dark); margin: 0; line-height: 1.5;">Support our ministries and
        services with a voluntary offering — any amount is welcome.</p>
    </div>
    <div style="flex: 0 0 auto;">
      <button type="button" class="btn btn-primary" onclick="openDonateModal()">Donate Now</button>
    </div>
  </div>
<?php endif; ?>

<div class="grid-3">
  <?php foreach ($services as $s): ?>
    <?php $isMassIntention = $s['category'] === 'Mass Intention'; ?>
    <div class="card" style="display:flex; flex-direction:column;">
      <h3><?= e($s['service_name']) ?></h3>
      <?php // Special Mass Request is filed under the Wake category internally; don't label it "Wake". ?>
      <?php if (!($s['category'] === 'Wake' && $s['service_name'] === 'Special Mass Request')): ?>
      <p class="text-muted" style="font-size:12.5px; text-transform:uppercase; letter-spacing:.4px;">
        <?= e($s['category']) ?></p>
      <?php endif; ?>
      <p style="font-size:14px;"><?= e($s['description']) ?></p>
      <?php
      $scheduleDisplay = null;
      if ($s['category'] === 'Anointing' || $s['category'] === 'Blessing') {
        $scheduleDisplay = 'Schedule arranged with the Parish Office';
      } elseif (in_array($s['category'], ['Baptism', 'Wedding', 'Confirmation'])) {
        $scheduleDesc = describeRegularSchedule((int) $s['service_id']);
        if (str_starts_with($scheduleDesc, 'No Regular schedule')) {
          $scheduleDisplay = 'Schedule arranged with the Parish Office';
        } else {
          $scheduleDisplay = $scheduleDesc;
        }
      } elseif ($s['category'] === 'Funeral') {
        $scheduleDisplay = 'Schedule arranged with the Parish Office';
      } elseif ($s['category'] === 'Wake') {
        $scheduleDisplay = 'Propose a preferred date &amp; time — parish office confirms';
      } elseif ($s['category'] === 'Mass Intention') {
        $scheduleDisplay = 'Daily 6:00 AM / Wed 5:15 PM / Sun 6:30 AM, 9:00 AM, 4:30 PM';
      } elseif ($s['category'] === 'Certificate') {
        $scheduleDisplay = 'Office pick-up schedule arranged with Parish Office';
      } else {
        $scheduleDisplay = 'Schedule arranged with the Parish Office';
      }
      ?>
      <?php if ($scheduleDisplay): ?>
        <p class="svc-schedule"
          style="font-size: 13px; font-weight: 500; color: var(--brown-dark); margin-bottom: 12px; margin-top: -6px; display: flex; align-items: center; gap: 4px;">
          <i data-lucide="calendar" style="width:14px; height:14px;"></i>
          <?= e($scheduleDisplay) ?>
        </p>
      <?php endif; ?>
      <?php if ($s['category'] === 'Anointing'): ?>
        <p class="svc-fee"
          style="font-size: 13px; color: var(--success, #2d7a46); font-weight: 600; margin-bottom: 12px; margin-top: -6px;">
          Free — No payment required</p>
      <?php endif; ?>
      <?php if (in_array($s['category'], ['Baptism', 'Wedding', 'Funeral'], true) && !empty($feeRulesByCategory[$s['category']])): ?>
        <p class="text-muted" style="font-size:13px; margin-bottom:4px;"><?php if ($s['category'] === 'Funeral'): ?>PSS
          Giver and Non-PSS Giver fees available.<?php else: ?>Regular and Special fees available by PSS
          classification.<?php endif; ?></p>
        <button type="button" class="btn btn-secondary btn-sm" style="align-self:flex-start; margin-bottom:12px;"
          onclick="document.getElementById('fees-<?= strtolower($s['category']) ?>').showModal()">View Fees</button>
      <?php endif; ?>
      <?php if ($s['requirements'] && !in_array($s['category'], ['Blessing', 'Anointing', 'Wake'], true)): ?>
        <details class="requirements-toggle" style="margin-bottom:12px;">
          <summary>Requirements</summary>
          <p><?= e($s['requirements']) ?></p>
        </details>
      <?php endif; ?>
      <div style="margin-top:auto; padding-top:16px;">
        <div style="margin-bottom:12px;">
          <span class="text-gold" style="font-weight:700; font-size:14px; display:block;">
            <?php if ($s['category'] === 'Funeral' && !empty($feeRulesByCategory['Funeral'])): ?>Fee varies by PSS status
            <?php else: ?><?= in_array($s['category'], $variableFeeCategories, true) ? 'Fee varies by schedule and PSS status' : feeLabel((float) $s['fee']) ?><?php endif; ?>
          </span>
        </div>
        <button type="button" class="btn btn-primary btn-block" style="width:100%;"
          onclick="openBookModal(<?= $s['service_id'] ?>)"><?= $isMassIntention ? 'Enter Intentions' : 'Book Now' ?></button>
      </div>
    </div>
  <?php endforeach; ?>

</div>

<?php
// Service fee tables: a ₱0.00 fee is "No Fees" (it is not a voluntary offering); amounts come from service_fee_rules.
$noFeesLabel = fn(float $amount): string => $amount > 0 ? money($amount) : 'No Fees';
?>
<?php foreach (['Baptism', 'Wedding'] as $feeCategory): ?>
  <?php if (!empty($feeRulesByCategory[$feeCategory])): ?>
    <?php $feeService = array_values(array_filter($services, fn($x) => $x['category'] === $feeCategory))[0] ?? null; ?>
    <?php if ($feeService): ?>
      <dialog class="modal" id="fees-<?= strtolower($feeCategory) ?>">
        <div class="modal-head">
          <h3><?= e($feeCategory) ?> Service Fees</h3><button type="button" class="modal-close"
            onclick="this.closest('dialog').close()">×</button>
        </div>
        <div class="modal-body">
          <?php foreach (['Regular', 'Special'] as $feeSchedule): ?>
            <h4><?= e($feeSchedule) ?> Schedule</h4>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th></th>
                    <th>PSS Giver</th>
                    <th>Non-PSS Giver</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $pssRule = array_values(array_filter($feeRulesByCategory[$feeCategory], fn($r) => $r['schedule_type'] === $feeSchedule && $r['pss_classification'] === 'pss'))[0] ?? null;
                  $nonRule = array_values(array_filter($feeRulesByCategory[$feeCategory], fn($r) => $r['schedule_type'] === $feeSchedule && $r['pss_classification'] === 'non_pss'))[0] ?? null; ?>
                  <?php if ($pssRule && $nonRule): ?>
                    <tr>
                      <td>Base Fee</td>
                      <td><?= $noFeesLabel((float) $pssRule['base_fee']) ?></td>
                      <td><?= $noFeesLabel((float) $nonRule['base_fee']) ?></td>
                    </tr>
                    <tr>
                      <td>Priest Stipend</td>
                      <td><?= $noFeesLabel((float) $pssRule['priest_stipend']) ?></td>
                      <td><?= $noFeesLabel((float) $nonRule['priest_stipend']) ?></td>
                    </tr>
                    <tr>
                      <td>Additional Sponsor</td>
                      <?php // The same included-sponsor counts the server charges with (service_fee_rules). ?>
                      <td><span style="font-size:0.85em;color:var(--gray-dark);">(No fees up to
                          <?= (int) $pssRule['included_sponsors'] ?>
                          sponsors)</span><br><?= $noFeesLabel((float) $pssRule['additional_sponsor_fee']) ?> per additional sponsor
                      </td>
                      <td><span style="font-size:0.85em;color:var(--gray-dark);">(No fees up to
                          <?= (int) $nonRule['included_sponsors'] ?>
                          sponsors)</span><br><?= $noFeesLabel((float) $nonRule['additional_sponsor_fee']) ?> per additional sponsor
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        </div>
      </dialog><?php endif; ?>
  <?php endif; ?>
<?php endforeach; ?>

<?php
// Funeral Mass: one fee table (no Regular/Special schedules), same modal design as Wedding/Baptism.
$funeralFeeRules = [];
foreach ($feeRulesByCategory['Funeral'] ?? [] as $r) $funeralFeeRules[$r['pss_classification']] = $r;
?>
<?php if (isset($funeralFeeRules['pss'], $funeralFeeRules['non_pss'])): ?>
  <dialog class="modal" id="fees-funeral">
    <div class="modal-head">
      <h3>Funeral Mass Service Fees</h3><button type="button" class="modal-close"
        onclick="this.closest('dialog').close()">×</button>
    </div>
    <div class="modal-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th></th>
              <th>PSS Giver</th>
              <th>Non-PSS Giver</th>
            </tr>
          </thead>
          <tbody>
            <?php $pssRule = $funeralFeeRules['pss']; $nonRule = $funeralFeeRules['non_pss']; ?>
            <tr>
              <td>Funeral Mass Service Fee</td>
              <td><?= $noFeesLabel((float) $pssRule['base_fee']) ?></td>
              <td><?= $noFeesLabel((float) $nonRule['base_fee']) ?></td>
            </tr>
            <tr>
              <td>Priest Stipend</td>
              <td><?= $noFeesLabel((float) $pssRule['priest_stipend']) ?></td>
              <td><?= $noFeesLabel((float) $nonRule['priest_stipend']) ?></td>
            </tr>
            <tr>
              <td><strong>Total Payment</strong></td>
              <td><strong><?= $noFeesLabel((float) $pssRule['base_fee'] + (float) $pssRule['priest_stipend']) ?></strong></td>
              <td><strong><?= $noFeesLabel((float) $nonRule['base_fee'] + (float) $nonRule['priest_stipend']) ?></strong></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </dialog>
<?php endif; ?>

<!-- ===================== Booking Modal ===================== -->
<style>
  /* ---- Booking Modal Refinements ---- */
  #bookModal {
    width: 680px;
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 40px);
    margin: auto;
    border: 1px solid var(--border, #d8cebe);
    border-radius: 16px;
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.35);
    overflow: hidden;
    background: var(--cream, #fdfbf7);
  }
  #bookModal .modal-body {
    padding: 18px 22px;
    max-height: calc(100vh - 165px);
    overflow-y: auto;
    overflow-x: hidden;
  }
  .book-modal-head {
    display: flex !important;
    flex-direction: column !important;
    align-items: stretch !important;
    padding: 0 !important;
    gap: 0 !important;
    background: var(--brown-dark, #241611) !important;
    border-bottom: 1px solid rgba(212, 175, 55, 0.25) !important;
    flex: 0 0 auto !important;
  }
  .book-modal-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px 10px;
  }
  .book-modal-top h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--ivory, #fdfbf7);
    letter-spacing: 0.3px;
  }
  /* Stepper inside header: unified dark & gold theme */
  .book-stepper {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px 24px 14px;
    background: rgba(0, 0, 0, 0.15);
    position: relative;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    box-sizing: border-box;
    width: 100%;
  }
  .book-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    position: relative;
    z-index: 2;
    cursor: default;
    user-select: none;
  }
  .book-step.done {
    cursor: pointer;
  }
  .book-step-connector {
    flex: 1;
    height: 2px;
    background: rgba(255, 255, 255, 0.18);
    margin: 0 8px 16px;
    transition: background 0.3s;
    position: relative;
    z-index: 1;
  }
  .book-step-connector.done {
    background: var(--gold, #c89b3c);
  }
  .book-step-circle {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.65);
    transition: all 0.3s;
  }
  .book-step.active .book-step-circle {
    border-color: var(--gold, #c89b3c);
    background: var(--gold, #c89b3c);
    color: var(--brown-darkest, #1a0e0a);
    box-shadow: 0 0 10px rgba(200, 155, 60, 0.45);
  }
  .book-step.done .book-step-circle {
    border-color: var(--gold, #c89b3c);
    background: var(--gold, #c89b3c);
    color: var(--brown-darkest, #1a0e0a);
  }
  .book-step-label {
    font-size: 11px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.6);
    text-align: center;
    letter-spacing: 0.2px;
    white-space: nowrap;
    transition: color 0.3s;
  }
  .book-step.active .book-step-label {
    color: var(--gold-light, #f5e6c4);
    font-weight: 700;
  }
  .book-step.done .book-step-label {
    color: var(--gold-light, #f5e6c4);
    font-weight: 600;
  }
  /* ---- Step panels ---- */
  .book-step-panel { display: none; }
  .book-step-panel.active { display: block; }
  /* ---- Nav buttons row ---- */
  .book-step-nav {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-top: 20px;
    padding-top: 14px;
    border-top: 1px solid var(--cream-dark, #e8e0d5);
    position: sticky;
    bottom: 0;
    background: var(--cream, #fdfbf7);
    z-index: 10;
  }
  .book-step-nav .btn-step-back {
    flex: 0 0 auto;
  }
  .book-step-nav .spacer { flex: 1; }
  .book-step-nav .btn-step-next,
  .book-step-nav .btn-step-submit {
    flex: 0 0 auto;
  }
  @media (max-width: 520px) {
    .book-stepper { padding: 8px 12px 10px; }
    .book-step-label { font-size: 10px; }
    .book-step-circle { width: 24px; height: 24px; font-size: 11px; }
  }
  /* Auto-capitalize text inputs and textareas (except email/password/dates) */
  #bookForm input[type="text"]:not([type="email"]),
  #bookForm textarea {
    text-transform: uppercase;
  }
</style>

<dialog class="modal modal-lg" id="bookModal">
  <div class="modal-head book-modal-head">
    <div class="book-modal-top">
      <h3 id="bookModalTitle">Book a Service</h3>
      <button type="button" class="modal-close" onclick="closeBookModal()">✕</button>
    </div>
    <!-- Step indicator -->
    <div class="book-stepper" id="bookStepper">
      <div class="book-step active" id="bstep-1" onclick="if(_bookCurrentStep>1) bookStepGo(1)" title="Step 1: Details">
        <div class="book-step-circle" id="bstep-circle-1">1</div>
        <span class="book-step-label">1. Details</span>
      </div>
      <div class="book-step-connector" id="bstep-conn-1"></div>
      <div class="book-step" id="bstep-2" onclick="if(_bookCurrentStep>2) bookStepGo(2)" title="Step 2: Documents">
        <div class="book-step-circle" id="bstep-circle-2">2</div>
        <span class="book-step-label">2. Documents</span>
      </div>
      <div class="book-step-connector" id="bstep-conn-2"></div>
      <div class="book-step" id="bstep-3" title="Step 3: Review &amp; Submit">
        <div class="book-step-circle" id="bstep-circle-3">3</div>
        <span class="book-step-label">3. Review &amp; Submit</span>
      </div>
    </div>
  </div>
  <div class="modal-body">

    <div id="bookFormView">

      <form method="POST" action="<?= url('parishioner/book.php') ?>" enctype="multipart/form-data" id="bookForm">
        <?= csrfField() ?>
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="draft_mode" id="draftModeInput" value="0">
        <input type="hidden" name="resume_draft" id="resumeDraftInput" value="">
        <style>
          /* JS sets #dateTimeRow to display:flex (overriding .form-row's grid),
             so split the two halves evenly with flex-basis 0 */
          #dateTimeRow,
          #funeralScheduleRow {
            display: flex;
            gap: 16px;
            align-items: flex-start;
          }

          #dateTimeRow>.form-group,
          #funeralScheduleRow>.form-group {
            flex: 1 1 0;
            min-width: 0;
            margin-bottom: 0;
          }

          #funeralScheduleRow { margin-bottom: 16px; }

          /* Funeral preferred schedule: side by side on desktop, stacked on phones. */
          @media (max-width: 600px) {
            #funeralScheduleRow { flex-direction: column; gap: 12px; }
            #funeralScheduleRow>.form-group { width: 100%; }
          }

          #dateTimeRow input[type="date"],
          #dateTimeRow input[type="time"],
          #funeralScheduleRow input[type="date"],
          #funeralScheduleRow input[type="time"],
          #dateTimeRow select,
          #funeralScheduleRow select,
          #precanaSpecialFields select,
          #priestSelect {
            width: 100%;
            box-sizing: border-box;
          }
        </style>

        <!-- ========== STEP 1: Details ========== -->
        <div class="book-step-panel active" id="book-panel-1">
          <div id="policyBox" class="alert"
            style="display:none; background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); margin-bottom:14px;">
          </div>
          <?php if ($identity['is_guest']): ?>
          <style>
            .guest-info-fields {
              display: grid;
              grid-template-columns: 1fr 1fr;
              gap: 12px;
            }

            .guest-info-fields .form-group {
              width: 100%;
              margin-bottom: 0;
            }

            @media (max-width: 520px) {
              .guest-info-fields {
                grid-template-columns: 1fr;
                gap: 10px;
              }
            }
          </style>
          <div
            style="background: var(--cream); padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; border: 1px solid var(--cream-dark);">
            <h4 style="margin-top:0; margin-bottom:12px; font-size:14px; color:var(--brown-dark);">Guest Information</h4>
            <div class="guest-info-fields">
              <div class="form-group">
                <label>First Name <span style="color:var(--danger);">*</span></label>
                <input type="text" name="guest_firstname" id="guestFirstNameInput" required placeholder="Juan"
                  style="width:100%;">
              </div>
              <div class="form-group">
                <label>Last Name <span style="color:var(--danger);">*</span></label>
                <input type="text" name="guest_lastname" id="guestLastNameInput" required placeholder="Dela Cruz"
                  style="width:100%;">
              </div>
              <div class="form-group">
                <label>Phone Number <span style="color:var(--danger);">*</span></label>
                <input type="tel" name="guest_phone" id="guestPhoneInput" required pattern="09[0-9]{9}" maxlength="11"
                  minlength="11" placeholder="09XXXXXXXXX" title="Must be exactly 11 digits starting with 09"
                  style="width:100%;">
              </div>
              <div class="form-group">
                <label>Email Address <span style="color:var(--danger);">*</span></label>
                <input type="email" name="guest_email" id="guestEmailInput" placeholder="you@example.com"
                  style="width:100%;" required maxlength="150">
              </div>
            </div>
            <input type="hidden" name="guest_middlename" id="guestMiddleNameInput" value="">
            <input type="hidden" name="guest_name" id="guestCombinedName">
            <p class="helper-text" style="margin-top:10px; margin-bottom:0; font-size:12px;">We'll use your name and phone number to
              identify your request — you'll get a reference code to check its status anytime.</p>
          </div>
        <?php else: ?>
          <p class="helper-text" style="margin-top:-4px; margin-bottom:14px;">We'll send updates about your booking to your registered email (<strong><?= e(currentUser()['email'] ?? '') ?></strong>).</p>
        <?php endif; ?>

        <div class="form-group">
          <label>Select Service</label>
          <select name="service_id" id="serviceSelect" required>
            <option value="">-- Choose a service --</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= $s['service_id'] ?>" data-category="<?= e($s['category']) ?>"
                data-service-name="<?= e($s['service_name']) ?>">
                <?= e($s['service_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="anointingFields"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 id="anointingFieldsTitle" style="margin-top:0;">Anointing of the Sick Details</h4>
          <div class="form-group">
            <label>Requester's Name <span style="color:var(--danger);">*</span></label>
            <input type="text" name="requester_name" id="requesterNameInput"
              placeholder="Full name of the person requesting">
          </div>
          <div class="form-group">
            <label id="patientNameLabel">Sick Person's Name <span style="color:var(--danger);">*</span></label>
            <input type="text" name="patient_name" id="patientNameInput"
              placeholder="Full name of the person to be anointed">
          </div>
          <div class="form-group">
            <label>Location/Address</label>
            <textarea name="location_address" id="anointingAddressInput" rows="2"
              placeholder="House number, street, barangay..."></textarea>
          </div>
          <p class="helper-text" id="anointingFieldsNote">This sacrament is provided <strong>free of charge</strong>. No
            payment is required.</p>
        </div>

        <div id="specialMassFields"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">Special Mass Request Details</h4>
          <div class="form-group">
            <label>Requester's Name <span style="color:var(--danger);">*</span></label>
            <input type="text" name="special_requester_name" id="specialRequesterInput"
              placeholder="Full name of the person requesting">
          </div>
          <div class="form-group">
            <label>Occasion <span style="color:var(--danger);">*</span></label>
            <select name="occasion" id="occasionSelect" onchange="updateOccasionUI()">
              <option value="">-- Select an occasion --</option>
              <option value="Death Anniversary">Death Anniversary</option>
              <option value="Birthday">Birthday</option>
              <option value="Wedding Anniversary">Wedding Anniversary</option>
              <option value="Thanksgiving">Thanksgiving</option>
              <option value="Healing and Recovery">Healing and Recovery</option>
              <option value="Special Intention">Special Intention</option>
              <option value="Others">Others</option>
            </select>
          </div>
          <div class="form-group">
            <label>Additional Requests <span id="specialRequestsRequiredMark"
                style="color:var(--danger); display:none;">*</span></label>
            <textarea name="special_requests" id="specialRequestsInput" rows="3"
              placeholder="Enter any other important intentions or requests..."></textarea>
            <p class="helper-text" id="specialRequestsHint" style="display:none;">Since you selected "Others", please
              type the specific occasion here.</p>
          </div>
          <div class="form-group">
            <label>Location / Address of the Mass</label>
            <textarea name="location_address" id="specialMassAddressInput" rows="2"
              placeholder="House number, street, barangay..."></textarea>
            <p class="helper-text">The complete address where the Mass will be celebrated.</p>
          </div>
          <p class="helper-text" style="margin-bottom:0;">Fixed fee: <strong>₱1,500</strong>. Propose a preferred date
            and time — our parish office will confirm.</p>
        </div>

        <div id="blessingFields"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">House Blessing Details</h4>
          <?php if (!$identity['is_guest']): ?>
            <div class="form-group">
              <label>Contact Phone Number</label>
              <input type="tel" name="contact_phone" id="blessingPhoneInput" value="<?= e($profilePhone) ?>"
                placeholder="09XX XXX XXXX">
            </div>
          <?php endif; ?>
          <div class="form-group">
            <label>Address / Location to Bless</label>
            <textarea name="location_address" id="blessingAddressInput" rows="2"
              placeholder="House number, street, barangay..."><?= e($profileAddress) ?></textarea>
            <p class="helper-text">This is just the address of the home/establishment — no document upload needed.</p>
          </div>
        </div>

        <div id="certificateFields"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">Certificate Request Details</h4>
          <p class="helper-text" style="margin-top:-4px; margin-bottom:12px;">
            Request for official church certificate. Fixed fee: <strong>₱100.00</strong>.
          </p>

          <div class="form-group">
            <label>Select Certificate <span style="color:var(--danger);">*</span></label>
            <select name="certificate_type" id="certificateTypeSelect" onchange="updateCertificateUI()">
              <option value="">-- Choose a certificate --</option>
              <option value="Baptismal Certificate">1. Baptismal Certificate - ₱100</option>
              <option value="Confirmation Certificate">2. Confirmation Certificate - ₱100</option>
              <option value="Death Certificate">3. Death Certificate - ₱100</option>
              <option value="Marriage Certificate">4. Marriage Certificate - ₱100</option>
            </select>
          </div>

          <!-- Common Requestor field (for 1. Baptismal, 2. Confirmation, 3. Death) -->
          <div class="form-group" id="certRequestorGroup" style="display:none;">
            <label>Requestor <span style="color:var(--danger);">*</span></label>
            <input type="text" name="cert_requestor" id="certRequestorInput"
              placeholder="Full name of person requesting" disabled>
          </div>

          <!-- 1. Baptismal certificate - 100 -->
          <div id="certBaptismalFields" style="display:none;">
            <div class="form-group">
              <label>Full Name of Registrant <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_baptism_registrant" id="certBaptismRegistrantInput"
                placeholder="Full name of registrant" disabled>
            </div>
            <div class="form-group">
              <label>Birthday / Year of Birth <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_baptism_birth" id="certBaptismBirthInput"
                placeholder="e.g. October 24, 1995 or 1995" disabled>
            </div>
            <div class="form-group">
              <label>Year of Baptism <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_baptism_year" id="certBaptismYearInput"
                placeholder="e.g. 1996" maxlength="4" disabled>
            </div>
          </div>

          <!-- 2. Confirmation certificate - 100 -->
          <div id="certConfirmationFields" style="display:none;">
            <div class="form-group">
              <label>Full Name of Registrant <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_confirm_registrant" id="certConfirmRegistrantInput"
                placeholder="Full name of registrant" disabled>
            </div>
            <div class="form-group">
              <label>Birthday / Year of Birth <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_confirm_birth" id="certConfirmBirthInput"
                placeholder="e.g. October 24, 1995 or 1995" disabled>
            </div>
            <div class="form-group">
              <label>Year of Confirmation <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_confirm_year" id="certConfirmYearInput"
                placeholder="e.g. 2008" maxlength="4" disabled>
            </div>
          </div>

          <!-- 3. Death certificate - 100 -->
          <div id="certDeathFields" style="display:none;">
            <div class="form-group">
              <label>Full Name of the Deceased <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_deceased_name" id="certDeceasedNameInput"
                placeholder="Full name of the deceased" disabled>
            </div>
            <div class="form-group">
              <label>Year of Death <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_death_year" id="certDeathYearInput"
                placeholder="e.g. 2021" maxlength="4" disabled>
            </div>
          </div>

          <!-- 4. Marriage certificate - 100 -->
          <div id="certMarriageFields" style="display:none;">
            <div class="form-group">
              <label>Full Name (Groom) <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_groom_name" id="certGroomNameInput"
                placeholder="Full name of groom" disabled>
            </div>
            <div class="form-group">
              <label>Full Name (Bride) <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_bride_name" id="certBrideNameInput"
                placeholder="Full name of bride" disabled>
            </div>
            <div class="form-group">
              <label>Year of Marriage <span style="color:var(--danger);">*</span></label>
              <input type="text" name="cert_marriage_year" id="certMarriageYearInput"
                placeholder="e.g. 2015" maxlength="4" disabled>
            </div>
          </div>
        </div>

        <div id="dateOfDeathGroup" class="form-group"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Date of Death</label>
          <input type="date" name="date_of_death" id="dateOfDeathInput" data-past max="<?= date('Y-m-d') ?>">
        </div>

        <?php if ($funeralScheduleEnabled): // Funeral only — same two-column layout as Baptism's Special date/time ?>
        <div class="form-row" id="funeralScheduleRow" style="display:none;">
          <div class="form-group">
            <label for="funeralPreferredDateInput">Preferred Burial Date</label>
            <input type="date" name="preferred_burial_date" id="funeralPreferredDateInput" min="<?= date('Y-m-d') ?>" disabled>
          </div>
          <div class="form-group">
            <label for="funeralPreferredTimeInput">Preferred Burial Time</label>
            <select name="preferred_burial_time" id="funeralPreferredTimeInput" disabled>
              <option value="">-- Select Time (30-min intervals) --</option>
              <?php foreach ($halfHourSlots as $val => $lbl): ?>
                <option value="<?= $val ?>"><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <?php endif; ?>

        <div class="form-group" id="scheduleTypeGroup"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Scheduling Option <span class="badge" id="scheduleTypeBadge" style="margin-left:6px;"></span></label>
          <label style="font-weight:400; display:block; margin-bottom:6px;">
            <input type="radio" name="schedule_type" value="Regular" id="scheduleTypeRegular" checked>
            Regular — choose from the parish's fixed available slots
          </label>
          <label style="font-weight:400; display:block;">
            <input type="radio" name="schedule_type" value="Special" id="scheduleTypeSpecial">
            Special — request a custom date &amp; time (availability is checked automatically)
          </label>
        </div>

        <?php // Baptism/Wedding keep the PSS claim here; for a Funeral, toggleServiceUI() shows this same block right under Select Service. ?>
        <span id="feeClassificationHome" hidden></span>
        <div id="feeClassificationGroup" class="form-group"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label for="pssClaimInput">PSS Classification Claim</label>
          <select name="pss_claim" id="pssClaimInput">
            <option value="">-- Select --</option>
            <option value="pss">PSS Giver (subject to verification)</option>
            <option value="non_pss">Non-PSS Giver</option>
          </select>
          <?php // No fee total here: the Secretary verifies the classification first, and the verified fee is shown on the appointment. ?>
          <p class="helper-text">The parish Secretary verifies this classification before the final fee is charged.</p>
        </div>
        <div id="sponsorCountGroup" class="form-group"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Number of Sponsors</label><input type="number" class="numeric-no-spinner numeric-zero-friendly"
            name="sponsor_count" id="sponsorCountInput" min="0" max="100" step="1" value="0">
        </div>
        <div id="weddingSponsorCountGroup" class="form-group"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Number of Individual Sponsors</label><input type="number"
            class="numeric-no-spinner numeric-zero-friendly" name="wedding_sponsor_count" id="weddingSponsorCountInput"
            min="0" max="200" step="1" value="0">
          <?php
            // From the Wedding fee rules the server charges with (PSS Giver rows; Regular and Special can differ).
            $weddingSponsorRule = [];
            foreach ($feeRulesByCategory['Wedding'] ?? [] as $r) if ($r['pss_classification'] === 'pss') $weddingSponsorRule[$r['schedule_type']] = $r;
          ?>
          <?php if (isset($weddingSponsorRule['Regular'], $weddingSponsorRule['Special'])): ?>
            <p class="helper-text">Regular schedule: the first <?= (int) $weddingSponsorRule['Regular']['included_sponsors'] ?> individual sponsors are included. Special schedule: the first <?= (int) $weddingSponsorRule['Special']['included_sponsors'] ?>. Each additional individual sponsor is <?= money((float) $weddingSponsorRule['Regular']['additional_sponsor_fee']) ?>.</p>
          <?php endif; ?>
        </div>
        <div id="regularSlotGroup" class="form-group" style="display:none;">
          <label>Preferred Date</label>
          <div class="mini-dp" id="regularMiniDp">
            <div class="mini-dp-field" id="regularDpField" tabindex="0">
              <span id="regularDpFieldText" class="mini-dp-placeholder">mm/dd/yyyy</span>
              <i data-lucide="calendar" style="width:16px; height:16px;"></i>
            </div>
            <div class="mini-dp-popup" id="regularDpPopup" style="display:none;">
              <div class="mini-dp-header">
                <button type="button" class="mini-dp-nav" id="regularDpPrev" aria-label="Previous month">‹</button>
                <span class="mini-dp-title" id="regularDpTitle"></span>
                <button type="button" class="mini-dp-nav" id="regularDpNext" aria-label="Next month">›</button>
              </div>
              <div class="mini-dp-weekdays">
                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
              </div>
              <div class="mini-dp-grid" id="regularDpGrid"></div>
              <p class="helper-text" id="regularSlotHint" style="margin: 6px 2px 0;">Loading available dates…</p>
              <div class="mini-dp-footer">
                <a href="#" id="regularDpClear">Clear</a>
                <a href="#" id="regularDpToday">Today</a>
              </div>
            </div>
          </div>
          <div id="regularFixedTimeBox"
            style="display:none; margin-top:8px; background: var(--cream); border-radius: 8px; padding: 10px 12px;">
            <strong>Time:</strong> <span id="regularFixedTimeText"></span> <span class="text-muted">(fixed for this
              schedule)</span>
            <p class="helper-text" style="margin: 4px 0 0;">Shared parish schedule — other families may also be scheduled at the same date and time.</p>
          </div>
          <input type="hidden" name="appointment_date" id="regularDateInput" disabled>
          <input type="hidden" name="appointment_time" id="regularTimeInput" disabled>
        </div>

        <div class="form-row" id="dateTimeRow">
          <div class="form-group">
            <label>Preferred Date</label>
            <input type="date" name="appointment_date" id="appointmentDateInput" required min="<?= date('Y-m-d') ?>"
              value="<?= e($preselectedDate) ?>">
          </div>
          <div class="form-group">
            <label>Preferred Time</label>

            <div id="freeTimeGroup">
              <select name="appointment_time" id="freeTimeInput">
                <option value="">-- Select Time (30-min intervals) --</option>
                <?php foreach ($halfHourSlots as $val => $lbl): ?>
                  <option value="<?= $val ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
              <p class="helper-text" id="occupiedTimesHint"></p>
            </div>

            <div id="fixedTimeGroup" style="display:none;">
              <input type="text" id="fixedTimeDisplay" disabled>
              <input type="hidden" name="appointment_time" id="fixedTimeInput">
            </div>

            <div id="massTimeGroup" style="display:none;">
              <select id="massTimeSelect"></select>
              <input type="hidden" name="appointment_time" id="massTimeInput">
              <p class="helper-text" id="massTimeHint">Select a date to see the available Mass times.</p>
            </div>
          </div>
        </div>

        <div id="specialSchedulingMessage" class="alert"
          style="display:none; background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); margin-bottom:18px;">
        </div>


        <div id="availabilityMsg" class="alert"
          style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <?php if ($precanaEnabled): // Wedding only — separate from the Wedding Ceremony schedule above ?>
        <div id="precanaGroup" class="form-group precana-group" style="display:none;">
          <label class="precana-heading">Pre-Cana Seminar Type <span style="color:var(--danger);">*</span></label>
          <div class="precana-options" role="radiogroup" aria-label="Pre-Cana Seminar Type">
            <label class="precana-option">
              <input type="radio" name="precana_type" value="Regular" id="precanaTypeRegular">
              <span class="precana-option-body">
                <strong>Regular Pre-Cana — No Fees</strong>
                <span>Every second Saturday of the month at 8:00 AM.</span>
              </span>
            </label>
            <label class="precana-option">
              <input type="radio" name="precana_type" value="Special" id="precanaTypeSpecial">
              <span class="precana-option-body">
                <strong>Special Pre-Cana — <?= e(money(PRECANA_SPECIAL_FEE)) ?></strong>
                <span>Choose your preferred seminar date and time, subject to Parish Office approval.</span>
              </span>
            </label>
          </div>
          <div id="precanaRegularFields" class="precana-fields" style="display:none;">
            <label id="precanaDpLabel">Regular Seminar Date</label>
            <?php // Same mini calendar as the Regular Wedding/Baptism date; only the server's seminar sessions can be picked. ?>
            <div class="mini-dp" id="precanaMiniDp">
              <div class="mini-dp-field" id="precanaDpField" tabindex="0" role="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="precanaDpPopup" aria-labelledby="precanaDpLabel precanaDpFieldText">
                <span id="precanaDpFieldText" class="mini-dp-placeholder">Select a seminar date</span>
                <i data-lucide="calendar" style="width:16px; height:16px;"></i>
              </div>
              <div class="mini-dp-popup" id="precanaDpPopup" role="dialog" aria-label="Choose a Regular Pre-Cana seminar date" style="display:none;">
                <div class="mini-dp-header">
                  <button type="button" class="mini-dp-nav" id="precanaDpPrev" aria-label="Previous month">‹</button>
                  <span class="mini-dp-title" id="precanaDpTitle" aria-live="polite"></span>
                  <button type="button" class="mini-dp-nav" id="precanaDpNext" aria-label="Next month">›</button>
                </div>
                <div class="mini-dp-weekdays" aria-hidden="true">
                  <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                </div>
                <div class="mini-dp-grid" id="precanaDpGrid"></div>
                <p class="helper-text" style="margin: 6px 2px 0;">Highlighted dates are the Regular Pre-Cana seminars (second Saturday, 8:00 AM).</p>
                <div class="mini-dp-footer">
                  <a href="#" id="precanaDpClear">Clear</a>
                  <a href="#" id="precanaDpClose">Close</a>
                </div>
              </div>
            </div>
            <div id="precanaFixedTimeBox" class="precana-fixed-time" style="display:none;">
              <strong>Time:</strong> 8:00 AM <span class="text-muted">(fixed for the Regular Pre-Cana seminar)</span>
            </div>
            <input type="hidden" name="precana_regular_date" id="precanaSessionSelect" disabled>
            <p class="helper-text" id="precanaRegularHint"></p>
          </div>
          <div id="precanaSpecialFields" class="precana-fields" style="display:none;">
            <div class="form-row">
              <div class="form-group">
                <label for="precanaDateInput">Preferred Seminar Date</label>
                <input type="date" name="precana_date" id="precanaDateInput" min="<?= date('Y-m-d') ?>" disabled>
              </div>
              <div class="form-group">
                <label for="precanaTimeInput">Preferred Seminar Time</label>
                <select name="precana_time" id="precanaTimeInput" disabled>
                  <option value="">-- Select Time (30-min intervals) --</option>
                  <?php foreach ($halfHourSlots as $val => $lbl): ?>
                    <option value="<?= $val ?>"><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <p class="precana-notice">Your preferred Pre-Cana schedule is subject to Parish Office approval.</p>
          </div>
          <p class="helper-text" style="margin-bottom:0;">The Pre-Cana Seminar Certificate is not needed to submit this booking — upload it after you attend the seminar.</p>
        </div>
        <?php endif; ?>

        <div class="form-group" id="priestFieldGroup">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:6px;">
            <label style="margin:0;">Preferred Priest (optional)</label>
            <button type="button" class="btn btn-outline btn-sm" id="btnAddAnotherPriest" style="display:none; font-size:12px; padding:3px 10px; border-radius:6px;">
              <span style="font-weight:700; font-size:13px; line-height:1; margin-right:3px;">+</span> Add Preferred Priest
            </button>
          </div>

          <div id="priestRowsContainer" style="display:flex; flex-direction:column; gap:10px;">
            <!-- Priest Row 1 -->
            <div class="priest-row" data-row="0" style="background:var(--cream, #f9f6f0); border:1px solid var(--cream-dark, #e5dec9); border-radius:8px; padding:10px;">
              <div style="display:flex; gap:8px; align-items:center;">
                <select name="priest_id" id="priestSelect" class="priest-select" style="flex:1;">
                  <option value="">No preference</option>
                  <?php foreach ($priests as $p): ?>
                    <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?>   <?= e($p['full_name']) ?></option>
                  <?php endforeach; ?>
                  <option value="other" id="priestOtherOption" style="display:none;">Other (Preferred Priest)</option>
                </select>
              </div>
              <div class="outside-priest-input-group" id="outsidePriestGroup" style="display:none; margin-top:8px;">
                <div style="display:flex; align-items:center;">
                  <span style="background:var(--cream-dark, #e8dfcb); border:1px solid #c9be9f; border-right:none; padding:8px 14px; border-radius:6px 0 0 6px; font-weight:700; color:var(--text, #333); font-size:14px; user-select:none;">Fr.</span>
                  <input type="text" id="outsidePriestInput" name="outside_priest_names[]" class="outside-priest-input" placeholder="ENTER PRIEST'S NAME (E.G. JUAN DELA CRUZ)" maxlength="150" style="border-top-left-radius:0; border-bottom-left-radius:0; flex:1; text-transform:uppercase;" autocomplete="off" disabled>
                </div>
                <p class="helper-text" style="margin:4px 0 0; font-size:11px;">
                  Title &ldquo;Fr.&rdquo; is automatically added. Type only the name of your priest.
                </p>
              </div>
            </div>
          </div>

          <p class="helper-text" id="priestHelpText" style="margin-top:6px; margin-bottom:0; font-size:12px;">Choosing a specific priest is subject to his availability — in case of unavoidable
            priest emergencies or pastoral duties, the parish office reserves the right to assign an available priest. Click <strong>+ Add Preferred Priest</strong> if you wish to request additional priests.
          </p>
        </div>

        <div id="intentionFields"
          style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">Mass Intention Details</h4>
          <p class="helper-text" style="margin-top:-4px;">No documents needed. You choose the offering amount. Online
            payments are verified through PayMongo; cash offerings remain pending until the Cashier confirms them.</p>
          <div class="form-row">
            <div class="form-group">
              <label>Intention Type</label>
              <select name="intention_type">
                <option>Living</option>
                <option>Dead</option>
                <option>Thanksgiving</option>
                <option>Healing</option>
                <option>Birthday</option>
              </select>
            </div>
            <div class="form-group">
              <label>Name</label>
              <input type="text" name="offerer_name" id="offererNameInput" placeholder="Name">
            </div>
          </div>
          <div class="form-group">
            <label>Intention For</label>
            <input type="text" name="intention_for" id="intentionForInput"
              placeholder="Name(s) the mass is offered for">
          </div>
          <div class="form-group">
            <label>Prayer Message (optional)</label>
            <textarea name="message" rows="2"></textarea>
          </div>

          <h4 style="margin-bottom:6px;">Offering &amp; Payment</h4>
          <div class="form-group">
            <label>Offering Amount (₱) — must be more than ₱0</label>
            <input type="number" name="amount" id="offeringAmount" class="amount-no-spinner" min="0.01" step="0.01"
              placeholder="e.g. 500" inputmode="decimal">
          </div>
          <div class="form-group">
            <label>How would you like to pay?</label>
            <label class="radio-option" style="display:block; margin-bottom:8px;">
              <input type="radio" name="pay_mode" value="online" checked>
              <strong>Pay Online Now</strong> — Card, GCash, or Maya via PayMongo (instant, secure)
            </label>
            <label class="radio-option" style="display:block;">
              <input type="radio" name="pay_mode" value="cash">
              <strong>Pay Later / In Person</strong> — Cash at Parish Office
            </label>
          </div>
        </div>

          <!-- Step 1 nav: Next button -->
          <div class="book-step-nav" id="step1-nav">
            <span class="spacer"></span>
            <button type="button" class="btn btn-primary btn-step-next" id="btnStep1Next" onclick="bookStepNext(1)">Next &rarr;</button>
          </div>
        </div><!-- /book-panel-1 -->

        <!-- ========== STEP 2: Documents ========== -->
        <div class="book-step-panel" id="book-panel-2">
          <div id="step2-empty-msg" class="alert"
            style="display:none; background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark); margin-bottom:16px;">
            No document uploads or requirements required for this service. You can proceed to Review &amp; Submit.
          </div>

          <!-- Requirements Preview for draft services (Baptism, Wedding, Funeral) -->
          <div id="requirementsPreviewBox"
            style="display:none; margin-top:0; margin-bottom:20px; border:1px solid var(--cream-dark); border-radius:8px; padding:16px; background:var(--white);">
            <h4 style="margin:0 0 8px; color:var(--brown-dark);">Requirements for the Next Step</h4>
            <p style="margin:0 0 16px; color:var(--brown-mid); font-size:14px;">Please prepare the following documents.
              You will upload them in the next step.</p>

            <div id="previewSupportingDocuments" style="margin-bottom:16px;">
              <h5
                style="margin:0 0 6px; font-size:13px; text-transform:uppercase; letter-spacing:1px; color:var(--gray-dark);">
                Supporting Documents</h5>
              <ul id="previewDocumentsList"
                style="margin:0; padding-left:20px; color:var(--brown-dark); font-size:14px; line-height:1.5;"></ul>
            </div>

            <div id="previewFormsToComplete">
              <h5
                style="margin:0 0 6px; font-size:13px; text-transform:uppercase; letter-spacing:1px; color:var(--gray-dark);">
                Forms to Complete</h5>
              <ul id="previewFormsList"
                style="margin:0; padding-left:20px; color:var(--brown-dark); font-size:14px; line-height:1.5;"></ul>
            </div>
          </div><!-- /requirementsPreviewBox -->

          <div class="form-group" id="documentRequirementsNote" style="display:none;">
            <label>Required Documents</label>
            <div class="alert"
              style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); font-size:13px; margin-bottom:12px;">
              <strong>Document Requirements:</strong>
              <ul style="margin:6px 0 0; padding-left:18px;">
                <li>Please upload a clear and readable document.</li>
                <li>Document must be in portrait orientation.</li>
                <li>Make sure all information is visible.</li>
                <li>Do not upload blurry, corrupted, or incorrect files.</li>
                <li>Upload the required document as a PDF, JPG, JPEG, or PNG file.</li>
                <li>Maximum file size: <?= e((string) MAX_DOCUMENT_UPLOAD_MB) ?> MB.</li>
              </ul>
              <p style="margin:8px 0 0;">These automated checks confirm a file is readable and correctly formatted — they
                do not verify authenticity. Our parish staff will do a final manual review before approval.</p>
            </div>
          </div>

          <div class="form-group" id="uploadGroup">
            <label>Required Documents</label>

            <div id="requirementRows"></div>

            <div class="form-group" style="margin-top:10px;">
              <label>Additional Documents (optional)</label>
              <input type="file" name="documents[]" id="extraDocumentsInput" multiple accept=".pdf,.jpg,.jpeg,.png">
              <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file,
                <?= e(ini_get('post_max_size')) ?> total for the whole form. If your photos are larger than that (common
                for phone camera photos), you can also upload documents later from the appointment detail page instead.
              </p>
            </div>
          </div><!-- /uploadGroup -->

          <!-- Step 2 nav -->
          <div class="book-step-nav" id="step2-nav">
            <button type="button" class="btn btn-outline btn-step-back" onclick="bookStepGo(1)">&larr; Back</button>
            <span class="spacer"></span>
            <button type="button" class="btn btn-primary btn-step-next" onclick="bookStepNext(2)">Next &rarr;</button>
          </div>
        </div><!-- /book-panel-2 -->

        <!-- ========== STEP 3: Review & Submit ========== -->
        <div class="book-step-panel" id="book-panel-3">

          <!-- Booking Summary Card -->
          <div class="book-review-card" id="bookReviewCard" style="background:var(--cream, #fdfbf7); border:1px solid var(--cream-dark, #e8e0d5); border-radius:8px; padding:16px; margin-bottom:18px;">
            <div style="font-weight:700; color:var(--brown-dark, #3d2b1f); font-size:14px; margin-bottom:10px; border-bottom:1px solid var(--cream-dark, #e8e0d5); padding-bottom:6px;">
              Booking Summary
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:13px;" id="bookReviewGrid">
              <div>
                <span style="color:var(--text-muted, #7a6e65); display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">Service</span>
                <strong id="reviewService" style="color:var(--brown-dark, #3d2b1f);">—</strong>
              </div>
              <div>
                <span style="color:var(--text-muted, #7a6e65); display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px;">Schedule</span>
                <strong id="reviewSchedule" style="color:var(--brown-dark, #3d2b1f);">—</strong>
              </div>
              <div id="reviewDetailsWrap" style="grid-column:1 / -1; display:none;">
                <span style="color:var(--text-muted, #7a6e65); display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Details</span>
                <div id="reviewDetails" style="color:var(--brown-dark, #3d2b1f);"></div>
              </div>
            </div>
          </div>

          <div class="form-group" id="remarksGroup">
            <label>Additional Remarks</label>
            <textarea name="remarks" id="remarksInput" rows="2" placeholder="Any special requests..."></textarea>
          </div>

          <div id="bookFormError" class="alert"
            style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

          <button type="submit" class="btn btn-primary btn-block" id="bookSubmitBtn">Submit Appointment Request</button>

          <!-- Step 3 nav: Back only -->
          <div class="book-step-nav" id="step3-nav">
            <button type="button" class="btn btn-outline btn-step-back" id="btnStep3Back" onclick="bookStepGo(2)">&larr; Back</button>
            <span class="spacer"></span>
          </div>
        </div><!-- /book-panel-3 -->
      </form>
    </div>

    <div id="bookConfirmView" style="display:none; text-align:center; padding: 20px 10px;">
      <div style="font-size:48px; margin-bottom:8px;">🙏</div>
      <h3 style="margin:0 0 6px; color: var(--brown-dark);">Thank You for Your Booking!</h3>
      <p style="color: var(--brown-mid); font-size:14px; margin: 0 0 12px;">Your request has been submitted and is
        pending review by our parish office.</p>
      <p id="bookConfirmScheduleType" style="display:none; margin: -4px 0 10px;"></p>
      <p id="bookConfirmMessage" style="color: var(--brown-mid); margin-bottom:16px; font-size:14px;"></p>
      <div id="bookConfirmReferenceBox"
        style="background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 10px; padding: 16px; margin-bottom:20px;">
        <p class="helper-text" style="margin:0 0 4px; font-size:13px;">Your booking reference code — save this to track
          your request anytime:</p>
        <p style="font-size:24px; font-weight:700; letter-spacing:2px; color: var(--brown-dark); margin:6px 0 8px;"
          id="bookConfirmReferenceCode"></p>
        <p style="font-size:12px; color: var(--text-muted, #888); margin:0;">Screenshot or note this code. You can use
          it on the <a href="<?= url('status.php') ?>">status page</a> anytime.</p>
      </div>
      <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap;">
        <a href="#" id="bookConfirmDetailLink" class="btn btn-outline" style="display:none;">View Appointment</a>
        <button type="button" class="btn btn-primary" onclick="closeBookModal()">Done</button>
      </div>
    </div>

  </div>
</dialog>

<?php include __DIR__ . '/../includes/donation-modal.php'; // Donate Now, ?donate=1, ?project_id=N (shared with Ongoing Projects) ?>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script
  src="<?= url('public/js/calendar.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/calendar.js') ?>"></script>
<script src="<?= url('public/js/scheduling.js') ?>"></script>
<script>
  var POLICIES = <?= json_encode($policies, JSON_UNESCAPED_UNICODE) ?>;
  var CALENDAR_BLOCKED = <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>;
  var REQUIREMENTS_BY_SERVICE = <?= json_encode($requirementsByService, JSON_UNESCAPED_UNICODE) ?>;
  var SCHEDULE_TOGGLE_CATEGORIES = <?= json_encode($scheduleToggleCategories) ?>;
  var CHECK_AVAILABILITY_URL = <?= json_encode(url('parishioner/check-availability.php')) ?>;
  var PRECANA = <?= json_encode([
      'enabled' => $precanaEnabled,
      'sessions' => $precanaSessions,
      'specialFee' => money(PRECANA_SPECIAL_FEE),
      'certificateLabel' => PRECANA_CERTIFICATE_LABEL,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

  /**
   * Pre-Cana seminar (Wedding only). Kept apart from the Wedding Ceremony
   * schedule: its own radios and fields, and only the fields of the chosen
   * type are enabled (so only they are submitted). Regular offers only the
   * calculated second-Saturday sessions before the wedding date — the date and
   * time cannot be typed. The server re-checks everything (includes/precana.php).
   */
  function weddingCeremonyDate() {
    var regular = document.getElementById('regularDateInput');
    if (regular && !regular.disabled && regular.value) return regular.value;
    var free = document.getElementById('appointmentDateInput');
    return free && !free.disabled ? free.value : '';
  }

  function selectedPrecanaType() {
    var regular = document.getElementById('precanaTypeRegular');
    var special = document.getElementById('precanaTypeSpecial');
    return regular && regular.checked ? 'Regular' : (special && special.checked ? 'Special' : '');
  }

  function updatePrecanaUI() {
    var group = document.getElementById('precanaGroup');
    if (!group) return;
    var serviceSelect = document.getElementById('serviceSelect');
    var isWedding = (serviceSelect.options[serviceSelect.selectedIndex]?.dataset.category || '') === 'Wedding';
    group.style.display = isWedding ? 'block' : 'none';
    ['precanaTypeRegular', 'precanaTypeSpecial'].forEach(function (id) {
      var radio = document.getElementById(id);
      radio.disabled = !isWedding;
      radio.required = isWedding;
    });
    var type = isWedding ? selectedPrecanaType() : '';
    var wedding = weddingCeremonyDate();

    var regularBox = document.getElementById('precanaRegularFields');
    var specialBox = document.getElementById('precanaSpecialFields');
    var sessionInput = document.getElementById('precanaSessionSelect');
    var dateInput = document.getElementById('precanaDateInput');
    var timeInput = document.getElementById('precanaTimeInput');
    regularBox.style.display = type === 'Regular' ? 'block' : 'none';
    specialBox.style.display = type === 'Special' ? 'block' : 'none';
    sessionInput.disabled = type !== 'Regular'; // only the chosen type's fields are submitted
    dateInput.disabled = timeInput.disabled = type !== 'Special';
    dateInput.required = timeInput.required = type === 'Special';
    if (type !== 'Regular') closePrecanaDp();

    // Regular: only the server's sessions before the wedding date can stay chosen.
    var sessions = precanaAvailableSessions();
    if (sessionInput.value && !sessions.some(function (s) { return s.date === sessionInput.value; })) sessionInput.value = '';
    syncPrecanaDpField();
    if (document.getElementById('precanaDpPopup').style.display !== 'none') renderPrecanaDpGrid();
    document.getElementById('precanaRegularHint').textContent = !wedding
      ? 'Choose your Wedding Ceremony date first — only seminar sessions before it can be selected.'
      : (sessions.length
        ? 'Fixed parish schedule (second Saturday, 8:00 AM) — the date and time cannot be changed.'
        : 'No Regular Pre-Cana seminar is available before your wedding date. Please contact the Parish Office or choose Special Pre-Cana.');

    // Special: the preferred date must be before the wedding.
    if (wedding) {
      var dayBefore = new Date(wedding + 'T00:00:00'); dayBefore.setDate(dayBefore.getDate() - 1);
      dateInput.max = dayBefore.getFullYear() + '-' + String(dayBefore.getMonth() + 1).padStart(2, '0') + '-' + String(dayBefore.getDate()).padStart(2, '0');
    } else {
      dateInput.removeAttribute('max');
    }
  }

  /**
   * Regular Pre-Cana calendar: the same mini calendar as the Regular
   * Wedding/Baptism date. Only PRECANA.sessions (calculated on the server:
   * second Saturdays, 8:00 AM, not past, not blocked) before the wedding date
   * are selectable; the chosen date goes into the hidden precana_regular_date
   * field exactly as the old dropdown sent it, and book.php re-checks it with
   * precanaValidate().
   */
  var precanaViewYear = null, precanaViewMonth = 0; // month 0-11
  // "Choose a seminar date" is shown once the person tries to submit, not the moment Regular is picked.
  var precanaShowMissingDate = false;
  window.addEventListener('submit', function (e) { if (e.target && e.target.id === 'bookForm') precanaShowMissingDate = true; }, true);

  function precanaAvailableSessions() {
    var wedding = weddingCeremonyDate();
    return PRECANA.sessions.filter(function (s) { return !wedding || s.date < wedding; });
  }

  function precanaSessionFor(date) {
    return PRECANA.sessions.filter(function (s) { return s.date === date; })[0] || null;
  }

  /** The field shows the chosen session (or the placeholder) and the fixed time. */
  function syncPrecanaDpField() {
    var value = document.getElementById('precanaSessionSelect').value;
    var text = document.getElementById('precanaDpFieldText');
    var session = value ? precanaSessionFor(value) : null;
    text.textContent = session ? session.label : 'Select a seminar date';
    text.className = session ? '' : 'mini-dp-placeholder';
    document.getElementById('precanaFixedTimeBox').style.display = session ? 'block' : 'none';
  }

  function precanaMonthKey(year, month) { return year + '-' + String(month + 1).padStart(2, '0'); }

  /** First/last month that has a selectable session (navigation stays inside them). */
  function precanaMonthRange() {
    var sessions = precanaAvailableSessions();
    if (!sessions.length) return null;
    return { first: sessions[0].date.slice(0, 7), last: sessions[sessions.length - 1].date.slice(0, 7) };
  }

  function renderPrecanaDpGrid() {
    var grid = document.getElementById('precanaDpGrid');
    var sessions = precanaAvailableSessions();
    var available = {};
    sessions.forEach(function (s) { available[s.date] = s; });
    var selected = document.getElementById('precanaSessionSelect').value;
    var range = precanaMonthRange();
    if (precanaViewYear === null) {
      var start = (selected && available[selected] ? selected : (sessions[0] ? sessions[0].date : todayStr())).split('-');
      precanaViewYear = parseInt(start[0], 10); precanaViewMonth = parseInt(start[1], 10) - 1;
    }
    var key = precanaMonthKey(precanaViewYear, precanaViewMonth);
    document.getElementById('precanaDpTitle').textContent = MONTH_NAMES[precanaViewMonth] + ' ' + precanaViewYear;
    document.getElementById('precanaDpPrev').disabled = !range || key <= range.first;
    document.getElementById('precanaDpNext').disabled = !range || key >= range.last;

    var startWeekday = new Date(precanaViewYear, precanaViewMonth, 1).getDay();
    var daysInMonth = new Date(precanaViewYear, precanaViewMonth + 1, 0).getDate();
    var today = todayStr();
    grid.textContent = '';
    for (var i = 0; i < 42; i++) {
      var day = i - startWeekday + 1;
      if (day < 1 || day > daysInMonth) {
        var blank = document.createElement('span');
        blank.className = 'mini-dp-day mini-dp-outside';
        blank.setAttribute('aria-hidden', 'true');
        grid.appendChild(blank);
        continue;
      }
      var dateStr = key + '-' + String(day).padStart(2, '0');
      var session = available[dateStr];
      var cell = document.createElement(session ? 'button' : 'span');
      cell.className = 'mini-dp-day' + (session ? ' mini-dp-available' : ' mini-dp-disabled')
        + (dateStr === selected ? ' mini-dp-selected' : '') + (dateStr === today ? ' mini-dp-today' : '');
      cell.textContent = day;
      if (session) {
        cell.type = 'button';
        cell.dataset.date = dateStr;
        cell.setAttribute('aria-label', session.label + (dateStr === selected ? ' (selected)' : ''));
        if (dateStr === selected) cell.setAttribute('aria-pressed', 'true');
      } else {
        cell.setAttribute('aria-disabled', 'true');
      }
      grid.appendChild(cell);
    }
  }

  function openPrecanaDp() {
    precanaViewYear = null; // start at the chosen session, else the first available one
    renderPrecanaDpGrid();
    var popup = document.getElementById('precanaDpPopup');
    popup.style.display = 'block';
    document.getElementById('precanaDpField').setAttribute('aria-expanded', 'true');
    if (popup.scrollIntoView) popup.scrollIntoView({ block: 'nearest' }); // fully visible inside the booking dialog
    var focusTarget = document.querySelector('#precanaDpGrid .mini-dp-selected') || document.querySelector('#precanaDpGrid button') || document.getElementById('precanaDpClose');
    if (focusTarget) focusTarget.focus();
  }

  function closePrecanaDp(returnFocus) {
    var popup = document.getElementById('precanaDpPopup');
    if (!popup || popup.style.display === 'none') return;
    popup.style.display = 'none';
    document.getElementById('precanaDpField').setAttribute('aria-expanded', 'false');
    if (returnFocus) document.getElementById('precanaDpField').focus();
  }

  function selectPrecanaSession(date) {
    if (!precanaAvailableSessions().some(function (s) { return s.date === date; })) return; // only listed sessions
    document.getElementById('precanaSessionSelect').value = date;
    syncPrecanaDpField();
    closePrecanaDp(true);
    // A missing-session message on the Regular option clears as soon as one is picked.
    var regularRadio = document.getElementById('precanaTypeRegular');
    precanaBeforeCheck();
    if (!regularRadio.validationMessage && typeof clearFieldError === 'function') clearFieldError(regularRadio);
    saveBookingSnapshotFromForm();
  }

  /** Inline messages for the rules the browser cannot express with attributes. */
  function precanaBeforeCheck() {
    var group = document.getElementById('precanaGroup');
    if (!group) return;
    var regularRadio = document.getElementById('precanaTypeRegular');
    var sessionInput = document.getElementById('precanaSessionSelect');
    var dateInput = document.getElementById('precanaDateInput');
    var timeInput = document.getElementById('precanaTimeInput');
    [regularRadio, dateInput, timeInput].forEach(function (el) { el.setCustomValidity(''); });
    if (group.style.display === 'none') return;
    var type = selectedPrecanaType();
    var wedding = weddingCeremonyDate();
    if (!type) { regularRadio.setCustomValidity('Please choose a Pre-Cana Seminar Type.'); return; }
    // The calendar's value is a hidden field, so its message is shown on the Regular option, right above the calendar.
    if (type === 'Regular' && !sessionInput.value && precanaShowMissingDate) {
      regularRadio.setCustomValidity(precanaAvailableSessions().length
        ? 'Please choose a Regular Pre-Cana seminar date on the calendar.'
        : 'No Regular Pre-Cana seminar is available before your wedding date. Please contact the Parish Office or choose Special Pre-Cana.');
    }
    if (type === 'Special') {
      if (dateInput.value && wedding && dateInput.value >= wedding) dateInput.setCustomValidity('The Pre-Cana seminar must be before the Wedding Ceremony date.');
      if (timeInput.value && !/:(00|30)$/.test(timeInput.value)) timeInput.setCustomValidity('Please choose a time in 30-minute intervals.');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bookForm');
    if (!form || !document.getElementById('precanaGroup')) return;
    form.addEventListener('change', updatePrecanaUI);
    form.addEventListener('input', updatePrecanaUI);
    // The Regular wedding calendar sets its hidden date without a change event.
    form.addEventListener('click', function () { setTimeout(updatePrecanaUI, 0); });

    // Regular Pre-Cana calendar (mouse and keyboard).
    var field = document.getElementById('precanaDpField');
    if (!field) return;
    field.addEventListener('click', function () {
      if (document.getElementById('precanaDpPopup').style.display === 'none') openPrecanaDp(); else closePrecanaDp(false);
    });
    field.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); openPrecanaDp(); }
    });
    document.getElementById('precanaDpPopup').addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePrecanaDp(true); }
    });
    document.getElementById('precanaDpPrev').addEventListener('click', function () {
      precanaViewMonth--; if (precanaViewMonth < 0) { precanaViewMonth = 11; precanaViewYear--; }
      renderPrecanaDpGrid();
    });
    document.getElementById('precanaDpNext').addEventListener('click', function () {
      precanaViewMonth++; if (precanaViewMonth > 11) { precanaViewMonth = 0; precanaViewYear++; }
      renderPrecanaDpGrid();
    });
    document.getElementById('precanaDpGrid').addEventListener('click', function (e) {
      var day = e.target.closest('button.mini-dp-day[data-date]');
      if (day) selectPrecanaSession(day.dataset.date);
    });
    document.getElementById('precanaDpClear').addEventListener('click', function (e) {
      e.preventDefault();
      document.getElementById('precanaSessionSelect').value = '';
      syncPrecanaDpField();
      renderPrecanaDpGrid();
      saveBookingSnapshotFromForm();
    });
    document.getElementById('precanaDpClose').addEventListener('click', function (e) { e.preventDefault(); closePrecanaDp(true); });
    document.addEventListener('click', function (e) {
      var wrap = document.getElementById('precanaMiniDp');
      if (wrap && !wrap.contains(e.target)) closePrecanaDp(false);
    });
  });

  function openBookModal(serviceId) {
    var modal = document.getElementById('bookModal');
    document.getElementById('bookFormView').style.display = 'block';
    document.getElementById('bookConfirmView').style.display = 'none';
    document.getElementById('bookFormError').style.display = 'none';
    // A deliberate fresh open starts blank, so any saved Back-navigation snapshot is stale.
    writeBookingSnapshot(null);
    document.getElementById('bookForm').reset();
    document.getElementById('resumeDraftInput').value = ''; // a fresh booking never updates an earlier draft

    var select = document.getElementById('serviceSelect');
    select.value = serviceId;
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    document.getElementById('bookModalTitle').textContent = category === 'Mass Intention' ? 'Enter Mass Intentions' : 'Book a Service';
    document.getElementById('scheduleTypeRegular').checked = true;

    <?php if ($preselectedDate): ?>
      document.getElementById('appointmentDateInput').value = <?= json_encode($preselectedDate) ?>;
    <?php endif; ?>

    // No need to reset KatinAwan payload as it's moved to the draft requirements step.

    toggleServiceUI();
    bookStepReset();
    modal.showModal();
    var mBody = modal.querySelector('.modal-body');
    if (mBody) mBody.scrollTop = 0;
  }

  function closeBookModal() {
    document.getElementById('bookModal').close();
  }

  /**
   * Booking rules the shared validator cannot read from attributes: a Mass
   * Intention needs an available Mass time and an offering above ₱0. Hidden
   * fields are skipped by the validator, so this is safe for other services.
   */
  function bookingBeforeCheck() {
    var serviceSelect = document.getElementById('serviceSelect');
    var category = serviceSelect.options[serviceSelect.selectedIndex]?.dataset.category || '';
    var isMass = category === 'Mass Intention';
    var amount = document.getElementById('offeringAmount');
    document.getElementById('massTimeSelect').setCustomValidity(
      isMass && !document.getElementById('massTimeInput').value ? 'Please choose an available Mass time.' : ''
    );
    amount.setCustomValidity(isMass && !(parseFloat(amount.value) > 0) ? 'Offering amount must be greater than ₱0.' : '');
    precanaBeforeCheck();
    funeralScheduleBeforeCheck();
    var priestSelect = document.getElementById('priestSelect');
    var outsideInput = document.getElementById('outsidePriestInput');
    if (priestSelect && priestSelect.value === 'other') {
      outsideInput.setCustomValidity(!outsideInput.value.trim() ? 'Please enter the name of your priest.' : '');
    } else if (outsideInput) {
      outsideInput.setCustomValidity('');
    }
    if (category === 'Certificate') {
      var certTypeSelect = document.getElementById('certificateTypeSelect');
      if (certTypeSelect) {
        certTypeSelect.setCustomValidity(!certTypeSelect.value ? 'Please choose a certificate to request.' : '');
      }
    }
  }

  /**
   * Keeps the Step 1 booking values when the user goes Back from a draft's
   * Supporting Documents page. Draft services (Baptism, Wedding, Funeral) leave
   * this page on submit, and the browser re-renders it on Back with an empty
   * form. The snapshot lives in sessionStorage (this tab only) and is restored
   * only on a back/forward navigation, so a fresh visit still starts blank.
   * File inputs are not restored: draft uploads are already saved server-side.
   */
  var BOOKING_SNAPSHOT_KEY = 'parishhubBookingSnapshot';

  function readBookingSnapshot() {
    try { return JSON.parse(sessionStorage.getItem(BOOKING_SNAPSHOT_KEY) || 'null'); } catch (e) { return null; }
  }

  function writeBookingSnapshot(snapshot) {
    try {
      if (snapshot) sessionStorage.setItem(BOOKING_SNAPSHOT_KEY, JSON.stringify(snapshot));
      else sessionStorage.removeItem(BOOKING_SNAPSHOT_KEY);
    } catch (e) { /* storage blocked: the form just will not survive Back */ }
  }

  function captureBookingForm() {
    var values = {};
    var radios = {};
    document.querySelectorAll('#bookForm input, #bookForm select, #bookForm textarea').forEach(function (el) {
      if (el.type === 'radio') {
        if (el.checked) radios[el.name] = el.value;
        return;
      }
      if (!el.id || el.type === 'file' || el.type === 'password') return;
      // Hidden fields are derived from other inputs, except the Mass time (must survive the date reload)
      // and the Regular Pre-Cana date chosen on its calendar.
      if (el.type === 'hidden' && el.id !== 'massTimeInput' && el.id !== 'precanaSessionSelect') return;
      values[el.id] = el.value;
    });
    return { serviceId: document.getElementById('serviceSelect').value, values: values, radios: radios };
  }

  function saveBookingSnapshotFromForm() {
    writeBookingSnapshot(captureBookingForm());
  }

  function restoreBookingForm(snapshot) {
    var serviceSelect = document.getElementById('serviceSelect');
    serviceSelect.value = snapshot.serviceId;
    if (serviceSelect.value !== snapshot.serviceId) return false; // service no longer offered

    Object.keys(snapshot.radios).forEach(function (name) {
      var radio = document.querySelector('#bookForm input[type="radio"][name="' + name + '"][value="' + snapshot.radios[name] + '"]');
      if (radio) radio.checked = true;
    });
    toggleServiceUI();

    Object.keys(snapshot.values).forEach(function (id) {
      var el = document.getElementById(id);
      if (el && el.type !== 'file') {
        el.value = snapshot.values[id];
        if (el.tagName === 'SELECT' && el.selectedIndex === -1 && typeof snapshot.values[id] === 'string' && snapshot.values[id].length >= 5) {
          el.value = snapshot.values[id].substring(0, 5);
        }
      }
    });

    if (snapshot.values['priestSelect'] === 'other' || snapshot.values['outsidePriestInput']) {
      var priestSelect = document.getElementById('priestSelect');
      var outsideGroup = document.getElementById('outsidePriestGroup');
      var outsideInput = document.getElementById('outsidePriestInput');
      if (priestSelect) priestSelect.value = 'other';
      if (outsideGroup) outsideGroup.style.display = 'block';
      if (outsideInput) {
        outsideInput.disabled = false;
        outsideInput.value = snapshot.values['outsidePriestInput'] || '';
      }
    }

    // Re-run the same side effects a user's own input would trigger.
    var dateInput = document.getElementById('appointmentDateInput');
    if (dateInput.value) dateInput.dispatchEvent(new Event('change'));
    var firstName = document.getElementById('guestFirstNameInput');
    if (firstName) firstName.dispatchEvent(new Event('input'));
    updateFuneralScheduleUI();
    refreshAvailability();
    return true;
  }

  /**
   * "Back to Booking Form" (?resume_draft=type-id): reopen the form for that
   * draft. The browser's own snapshot of what was typed is used when it belongs
   * to the same service; otherwise the draft's saved values refill the form.
   * The hidden resume_draft field makes book.php update that draft in place.
   */
  var RESUME_DRAFT = <?= json_encode($resumeDraft ? [
    'key' => $resumeDraft['type'] . '-' . $resumeDraft['id'],
    'snapshot' => [
      'serviceId' => (string) $resumeDraft['service_id'],
      'radios' => array_filter(['schedule_type' => $resumeDraft['schedule_type'], 'precana_type' => $resumeDraft['precana_type'] ?? null]) ?: (object) [],
      'values' => array_filter([
        'priestSelect' => $resumeDraft['priest_id'] !== null ? (string) $resumeDraft['priest_id'] : (!empty($resumeDraft['outside_priest_name']) ? 'other' : null),
        'outsidePriestInput' => !empty($resumeDraft['outside_priest_name']) ? preg_replace('/^(?:rev(?:erend)?\.?\s*(?:fr\.?|father)?|fr\.?|father)\s+/i', '', $resumeDraft['outside_priest_name']) : null,
        'appointmentDateInput' => $resumeDraft['date'],
        'freeTimeInput' => !empty($resumeDraft['time']) ? substr($resumeDraft['time'], 0, 5) : null,
        'pssClaimInput' => $resumeDraft['pss_claim'],
        'sponsorCountInput' => $resumeDraft['sponsor_count'] !== null ? (string) $resumeDraft['sponsor_count'] : null,
        'weddingSponsorCountInput' => $resumeDraft['wedding_sponsor_count'] !== null ? (string) $resumeDraft['wedding_sponsor_count'] : null,
        'remarksInput' => $resumeDraft['remarks'],
        'dateOfDeathInput' => $resumeDraft['date_of_death'],
        // Funeral preferred burial / Funeral Mass schedule.
        'funeralPreferredDateInput' => $resumeDraft['preferred_burial_date'] ?? null,
        'funeralPreferredTimeInput' => !empty($resumeDraft['preferred_burial_time']) ? substr($resumeDraft['preferred_burial_time'], 0, 5) : null,
        // Pre-Cana seminar: Regular keeps its session, Special its preferred date/time.
        'precanaSessionSelect' => ($resumeDraft['precana_type'] ?? null) === 'Regular' ? $resumeDraft['precana_date'] : null,
        'precanaDateInput' => ($resumeDraft['precana_type'] ?? null) === 'Special' ? $resumeDraft['precana_date'] : null,
        'precanaTimeInput' => (($resumeDraft['precana_type'] ?? null) === 'Special' && !empty($resumeDraft['precana_time'])) ? substr($resumeDraft['precana_time'], 0, 5) : null,
      ], fn($v) => $v !== null && $v !== ''),
    ],
  ] : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function resumeDraftBooking() {
    var saved = readBookingSnapshot();
    var snapshot = saved && saved.serviceId === RESUME_DRAFT.snapshot.serviceId ? saved : RESUME_DRAFT.snapshot;
    document.getElementById('bookFormView').style.display = 'block';
    document.getElementById('bookConfirmView').style.display = 'none';
    document.getElementById('bookFormError').style.display = 'none';
    document.getElementById('bookForm').reset();
    if (!restoreBookingForm(snapshot)) return;
    document.getElementById('resumeDraftInput').value = RESUME_DRAFT.key;
    bookStepReset();
    document.getElementById('bookModal').showModal();
    // Drop ?resume_draft from the address bar so a reload or a later Book Now starts fresh.
    if (window.history && history.replaceState) history.replaceState(null, '', window.location.pathname);
  }

  var resumedFromDraft = false;

  function resumeBookingAfterBack() {
    if (resumedFromDraft) return; // already reopened from "Back to Booking Form" on DOMContentLoaded
    var snapshot = readBookingSnapshot();
    if (!snapshot) return;

    var nav = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
    var cameBack = nav ? nav.type === 'back_forward' : false;
    if (!cameBack) {
      writeBookingSnapshot(null); // a fresh visit starts blank
      return;
    }

    document.getElementById('bookFormView').style.display = 'block';
    document.getElementById('bookConfirmView').style.display = 'none';
    document.getElementById('bookFormError').style.display = 'none';
    if (restoreBookingForm(snapshot)) {
      bookStepReset();
      document.getElementById('bookModal').showModal();
    } else {
      writeBookingSnapshot(null);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bookForm');
    form.addEventListener('input', saveBookingSnapshotFromForm);
    form.addEventListener('change', saveBookingSnapshotFromForm);
    form.addEventListener('submit', saveBookingSnapshotFromForm);
  });

  // pageshow runs after DOMContentLoaded, so the form's change listeners are attached before a restore fires them.
  window.addEventListener('pageshow', resumeBookingAfterBack);

  /**
   * Certificate Request: Toggle input fields based on selected certificate type.
   * 1. Baptismal: Requestor, Registrant, Birthday/Birth Year, Baptism Year
   * 2. Confirmation: Requestor, Registrant, Birthday/Birth Year, Confirmation Year
   * 3. Death: Requestor, Deceased, Death Year
   * 4. Marriage: Groom, Bride, Marriage Year
   */
  function updateCertificateUI() {
    var certFields = document.getElementById('certificateFields');
    if (!certFields) return;
    var isCertVisible = certFields.style.display !== 'none';
    var certTypeSelect = document.getElementById('certificateTypeSelect');
    var certType = certTypeSelect ? certTypeSelect.value : '';

    var isBaptismal = isCertVisible && certType === 'Baptismal Certificate';
    var isConfirmation = isCertVisible && certType === 'Confirmation Certificate';
    var isDeath = isCertVisible && certType === 'Death Certificate';
    var isMarriage = isCertVisible && certType === 'Marriage Certificate';

    var showRequestor = isBaptismal || isConfirmation || isDeath;
    var reqGroup = document.getElementById('certRequestorGroup');
    var reqInput = document.getElementById('certRequestorInput');
    if (reqGroup && reqInput) {
      reqGroup.style.display = showRequestor ? 'block' : 'none';
      reqInput.required = showRequestor;
      reqInput.disabled = !showRequestor;
    }

    var baptBox = document.getElementById('certBaptismalFields');
    if (baptBox) {
      baptBox.style.display = isBaptismal ? 'block' : 'none';
      ['certBaptismRegistrantInput', 'certBaptismBirthInput', 'certBaptismYearInput'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.required = isBaptismal; el.disabled = !isBaptismal; }
      });
    }

    var confBox = document.getElementById('certConfirmationFields');
    if (confBox) {
      confBox.style.display = isConfirmation ? 'block' : 'none';
      ['certConfirmRegistrantInput', 'certConfirmBirthInput', 'certConfirmYearInput'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.required = isConfirmation; el.disabled = !isConfirmation; }
      });
    }

    var deathBox = document.getElementById('certDeathFields');
    if (deathBox) {
      deathBox.style.display = isDeath ? 'block' : 'none';
      ['certDeceasedNameInput', 'certDeathYearInput'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.required = isDeath; el.disabled = !isDeath; }
      });
    }

    var marrBox = document.getElementById('certMarriageFields');
    if (marrBox) {
      marrBox.style.display = isMarriage ? 'block' : 'none';
      ['certGroomNameInput', 'certBrideNameInput', 'certMarriageYearInput'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.required = isMarriage; el.disabled = !isMarriage; }
      });
    }
  }

  /**
   * Special Mass Request: "Others" makes the Additional Requests box mandatory,
   * since the typed text is the occasion itself. Only active while the Special
   * Mass Request block is visible, so it never affects other services.
   */
  function updateOccasionUI() {
    var specialVisible = document.getElementById('specialMassFields').style.display !== 'none';
    var isOthers = specialVisible && document.getElementById('occasionSelect').value === 'Others';
    var requestsInput = document.getElementById('specialRequestsInput');
    requestsInput.required = isOthers;
    requestsInput.placeholder = isOthers
      ? 'Type the occasion and any other intentions or requests...'
      : 'Enter any other important intentions or requests...';
    document.getElementById('specialRequestsRequiredMark').style.display = isOthers ? 'inline' : 'none';
    document.getElementById('specialRequestsHint').style.display = isOthers ? 'block' : 'none';
  }

  var _cachedPriestOptionsHtml = '';

  function getPriestOptionsMarkup(currentVal) {
    if (_cachedPriestOptionsHtml) {
      var div = document.createElement('div');
      div.innerHTML = '<select>' + _cachedPriestOptionsHtml + '</select>';
      var sel = div.querySelector('select');
      sel.value = currentVal || '';
      return sel.innerHTML;
    }
    var selOrig = document.getElementById('priestSelect');
    if (!selOrig) return '<option value="">No preference</option><option value="other">Other (Preferred Priest)</option>';
    return selOrig.innerHTML;
  }

  function addPriestRow(defaultToOther) {
    var container = document.getElementById('priestRowsContainer');
    if (!container) return;
    var rows = container.querySelectorAll('.priest-row');
    var rowIdx = rows.length;

    var rowDiv = document.createElement('div');
    rowDiv.className = 'priest-row';
    rowDiv.dataset.row = String(rowIdx);
    rowDiv.style.cssText = 'background:var(--cream, #f9f6f0); border:1px solid var(--cream-dark, #e5dec9); border-radius:8px; padding:10px; position:relative;';

    var headerDiv = document.createElement('div');
    headerDiv.style.cssText = 'display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;';
    headerDiv.innerHTML = '<span style="font-weight:600; font-size:12px; color:var(--brown-dark, #5c4033);">Preferred Priest #' + (rowIdx + 1) + '</span>' +
      '<button type="button" class="btn btn-outline btn-sm btn-remove-priest-row" title="Remove this priest" style="padding:2px 8px; font-size:12px; color:var(--danger, #dc2626); border-color:#fca5a5; line-height:1; cursor:pointer;">✕ Remove</button>';

    var selectWrap = document.createElement('div');
    selectWrap.style.cssText = 'display:flex; gap:8px; align-items:center;';
    var selectEl = document.createElement('select');
    selectEl.name = 'additional_priest_ids[]';
    selectEl.className = 'priest-select';
    selectEl.style.cssText = 'flex:1;';
    selectEl.innerHTML = getPriestOptionsMarkup(defaultToOther ? 'other' : '');
    if (defaultToOther) selectEl.value = 'other';
    selectWrap.appendChild(selectEl);

    var inputGroup = document.createElement('div');
    inputGroup.className = 'outside-priest-input-group';
    inputGroup.style.cssText = 'margin-top:8px; display:' + (defaultToOther ? 'block' : 'none') + ';';
    inputGroup.innerHTML = '<div style="display:flex; align-items:center;">' +
      '<span style="background:var(--cream-dark, #e8dfcb); border:1px solid #c9be9f; border-right:none; padding:8px 14px; border-radius:6px 0 0 6px; font-weight:700; color:var(--text, #333); font-size:14px; user-select:none;">Fr.</span>' +
      '<input type="text" name="outside_priest_names[]" class="outside-priest-input" placeholder="ENTER PRIEST\'S NAME (E.G. JUAN DELA CRUZ)" maxlength="150" style="border-top-left-radius:0; border-bottom-left-radius:0; flex:1; text-transform:uppercase;" autocomplete="off"' + (defaultToOther ? '' : ' disabled') + '>' +
      '</div>' +
      '<p class="helper-text" style="margin:4px 0 0; font-size:11px;">Title &ldquo;Fr.&rdquo; is automatically added. Type only the name of your priest.</p>';

    rowDiv.appendChild(headerDiv);
    rowDiv.appendChild(selectWrap);
    rowDiv.appendChild(inputGroup);
    container.appendChild(rowDiv);

    var inputEl = inputGroup.querySelector('.outside-priest-input');

    selectEl.addEventListener('change', function () {
      if (this.value === 'other') {
        inputGroup.style.display = 'block';
        inputEl.disabled = false;
        inputEl.focus();
      } else {
        inputGroup.style.display = 'none';
        inputEl.disabled = true;
        inputEl.value = '';
      }
    });

    headerDiv.querySelector('.btn-remove-priest-row').addEventListener('click', function () {
      rowDiv.remove();
      var remaining = container.querySelectorAll('.priest-row');
      remaining.forEach(function (r, idx) {
        r.dataset.row = String(idx);
        var label = r.querySelector('span');
        if (label && idx > 0) label.textContent = 'Preferred Priest #' + (idx + 1);
      });
    });

    if (defaultToOther && inputEl) {
      setTimeout(function () { inputEl.focus(); }, 50);
    }
  }

  /**
   * Visiting / Outside / Preferred Priest ("dili atoa nga pari"):
   * Allows selecting or typing 1, 2, or more preferred priests.
   */
  function updateOutsidePriestUI() {
    var select = document.getElementById('serviceSelect');
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    var allowsOutside = category !== '' && category !== 'Donation' && category !== 'Mass Intention' && category !== 'Certificate';
    var btnAdd = document.getElementById('btnAddAnotherPriest');
    var opt = document.getElementById('priestOtherOption');
    var priestSelect = document.getElementById('priestSelect');
    var outsideGroup = document.getElementById('outsidePriestGroup');
    var outsideInput = document.getElementById('outsidePriestInput');

    if (btnAdd) btnAdd.style.display = allowsOutside ? 'inline-flex' : 'none';
    if (opt) opt.style.display = allowsOutside ? '' : 'none';

    if (!allowsOutside) {
      if (outsideGroup) outsideGroup.style.display = 'none';
      if (outsideInput) { outsideInput.value = ''; outsideInput.disabled = true; outsideInput.setCustomValidity(''); }
      if (priestSelect && priestSelect.value === 'other') priestSelect.value = '';
      var container = document.getElementById('priestRowsContainer');
      if (container) {
        var extra = container.querySelectorAll('.priest-row:not([data-row="0"])');
        extra.forEach(function (r) { r.remove(); });
      }
    } else if (priestSelect && priestSelect.value === 'other') {
      if (outsideGroup) outsideGroup.style.display = 'block';
      if (outsideInput) outsideInput.disabled = false;
    }
  }

  function getScheduleType() {
    return document.getElementById('scheduleTypeSpecial').checked ? 'Special' : 'Regular';
  }

  /**
   * Funeral only: the PSS Classification Claim sits right under Select Service.
   * Baptism and Wedding keep it in its usual place (#feeClassificationHome).
   * It is one block (one pss_claim field), only moved, never duplicated.
   */
  function placeFeeClassification(category) {
    var group = document.getElementById('feeClassificationGroup');
    var serviceGroup = document.getElementById('serviceSelect').closest('.form-group');
    if (category === 'Funeral') {
      if (serviceGroup.nextElementSibling !== group) serviceGroup.insertAdjacentElement('afterend', group);
    } else {
      var home = document.getElementById('feeClassificationHome');
      if (home.nextElementSibling !== group) home.insertAdjacentElement('afterend', group);
    }
  }

  function toggleServiceUI() {
    var select = document.getElementById('serviceSelect');
    var selectedOption = select.options[select.selectedIndex];
    var category = selectedOption ? selectedOption.dataset.category || '' : '';
    var serviceId = selectedOption ? selectedOption.value : '';

    // Clear any stale error from a previous service selection
    document.getElementById('bookFormError').style.display = 'none';

    var needsClassification = ['Baptism', 'Wedding', 'Funeral'].indexOf(category) !== -1;
    document.getElementById('feeClassificationGroup').style.display = needsClassification ? 'block' : 'none';
    document.getElementById('pssClaimInput').required = needsClassification;
    placeFeeClassification(category);
    document.getElementById('sponsorCountGroup').style.display = category === 'Baptism' ? 'block' : 'none';
    document.getElementById('weddingSponsorCountGroup').style.display = category === 'Wedding' ? 'block' : 'none';
    document.getElementById('sponsorCountInput').required = category === 'Baptism';
    document.getElementById('weddingSponsorCountInput').required = category === 'Wedding';
    // Handle generic Requirements Preview for draft services
    var previewBox = document.getElementById('requirementsPreviewBox');
    if (category === 'Wedding' || category === 'Baptism' || category === 'Funeral') {
      previewBox.style.display = 'block';
      renderRequirementsPreview(serviceId, category);
    } else {
      previewBox.style.display = 'none';
    }
    var bookSubmitBtn = document.getElementById('bookSubmitBtn');
    if (bookSubmitBtn.getAttribute('aria-busy') !== 'true') bookSubmitBtn.textContent = submitButtonLabel(); // keep "Submitting…" while sending
    // Synchronous first, so a restored Pre-Cana session finds its option; again
    // once this function has set up the wedding date fields.
    updatePrecanaUI();
    setTimeout(updatePrecanaUI, 0);

    var isMassIntention = category === 'Mass Intention';
    var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;
    var scheduleType = usesToggle ? getScheduleType() : null;

    document.getElementById('bookModalTitle').textContent = isMassIntention ? 'Enter Mass Intentions' : 'Book a Service';
    document.getElementById('intentionFields').style.display = isMassIntention ? 'block' : 'none';
    document.getElementById('offererNameInput').required = isMassIntention;
    document.getElementById('intentionForInput').required = isMassIntention;
    // The visible Mass-time picker is a UI control inside a conditionally
    // displayed group; it is not the submitted field. Native required
    // validation on it can block the form before our inline error handling
    // runs, especially while the date/time options are still loading.
    document.getElementById('massTimeSelect').required = false;
    updatePayModeUI();
    document.getElementById('dateOfDeathGroup').style.display = category === 'Funeral' ? 'block' : 'none';
    document.getElementById('dateOfDeathInput').required = (category === 'Funeral');

    // "Special Mass Request" shares the Wake category but has its own block
    // (occasion + additional requests). Only plain Wake Mass uses the
    // deceased-name block, so the two never show or require each other's fields.
    var serviceName = selectedOption ? selectedOption.dataset.serviceName || '' : '';
    var isSpecialMass = category === 'Wake' && serviceName === 'Special Mass Request';

    var isAnointing = category === 'Anointing';
    var isWakeMass = category === 'Wake' && !isSpecialMass;
    var showAnointingBlock = isAnointing || isWakeMass;
    document.getElementById('anointingFields').style.display = showAnointingBlock ? 'block' : 'none';
    document.getElementById('requesterNameInput').required = showAnointingBlock;
    document.getElementById('patientNameInput').required = showAnointingBlock;
    // Like the Special Mass / House Blessing addresses, this posts as location_address,
    // so it is only enabled (and submitted) for Anointing and Wake Mass.
    var anointingAddress = document.getElementById('anointingAddressInput');
    anointingAddress.required = showAnointingBlock;
    anointingAddress.disabled = !showAnointingBlock;

    document.getElementById('specialMassFields').style.display = isSpecialMass ? 'block' : 'none';
    document.getElementById('specialRequesterInput').required = isSpecialMass;
    document.getElementById('occasionSelect').required = isSpecialMass;
    // Both this and the House Blessing address post as location_address, so only
    // the one for the selected service is enabled (and submitted).
    var specialMassAddress = document.getElementById('specialMassAddressInput');
    specialMassAddress.required = isSpecialMass;
    specialMassAddress.disabled = !isSpecialMass;
    // Remarks are folded into the Special Mass Request's own text box, so the
    // generic remarks field is hidden and disabled (not submitted) for it.
    document.getElementById('remarksGroup').style.display = isSpecialMass ? 'none' : 'block';
    document.getElementById('remarksInput').disabled = isSpecialMass;
    updateOccasionUI();

    if (isWakeMass) {
      // Wake Mass is the only service left in this block with a deceased name.
      document.getElementById('anointingFieldsTitle').textContent = selectedOption.textContent.trim() + ' Details';
      document.getElementById('patientNameLabel').innerHTML = "Deceased Person's Name <span style=\"color:var(--danger);\">*</span>";
      document.getElementById('patientNameInput').placeholder = 'Full name of the deceased';      document.getElementById('anointingFieldsNote').innerHTML = 'Fixed fee: <strong>₱1,500</strong>. Propose a preferred date and time — our parish office will confirm.';
    } else {
      document.getElementById('anointingFieldsTitle').textContent = 'Anointing of the Sick Details';
      document.getElementById('patientNameLabel').innerHTML = "Sick Person's Name <span style=\"color:var(--danger);\">*</span>";
      document.getElementById('patientNameInput').placeholder = 'Full name of the person to be anointed';      document.getElementById('anointingFieldsNote').innerHTML = 'This sacrament is provided <strong>free of charge</strong>. No payment is required.';
    }

    var isBlessing = category === 'Blessing';
    document.getElementById('blessingFields').style.display = isBlessing ? 'block' : 'none';
    document.getElementById('blessingAddressInput').required = isBlessing;
    document.getElementById('blessingAddressInput').disabled = !isBlessing;
    var blessingPhone = document.getElementById('blessingPhoneInput');
    if (blessingPhone) blessingPhone.required = isBlessing;

    var isCertificate = category === 'Certificate';
    var certFields = document.getElementById('certificateFields');
    if (certFields) certFields.style.display = isCertificate ? 'block' : 'none';
    var certSelect = document.getElementById('certificateTypeSelect');
    if (certSelect) {
      certSelect.required = isCertificate;
      certSelect.disabled = !isCertificate;
      if (isCertificate) {
        updateCertificateUI();
      } else if (certFields) {
        var certInputs = certFields.querySelectorAll('input');
        certInputs.forEach(function(inp) { inp.required = false; inp.disabled = true; });
      }
    }

    // Priest selection (preferred parish priest or visiting priest) is available for all services except Donation, Mass Intention, and Certificate
    var priestGroup = document.getElementById('priestFieldGroup');
    var priestSelect = document.getElementById('priestSelect');
    var hidePriest = category === 'Donation' || isMassIntention || isCertificate;
    priestGroup.style.display = hidePriest ? 'none' : 'block';
    priestSelect.disabled = hidePriest;
    if (hidePriest) {
      priestSelect.value = '';
      var outsideGroup = document.getElementById('outsidePriestGroup');
      if (outsideGroup) outsideGroup.style.display = 'none';
      var outsideInput = document.getElementById('outsidePriestInput');
      if (outsideInput) {
        outsideInput.value = '';
        outsideInput.disabled = true;
      }
      var btnOthers = document.getElementById('btnPriestOthers');
      if (btnOthers) btnOthers.style.display = 'none';
    }

    // No documents for Mass Intentions, Certificate requests, drafts (Baptism/Wedding/Funeral), or
    // services that handle documents separately (Anointing, Blessing, Wake).
    var uploadGroup = document.getElementById('uploadGroup');
    var draftService = category === 'Baptism' || category === 'Wedding' || category === 'Funeral';
    var noDocsService = isMassIntention || draftService || category === 'Anointing' || category === 'Blessing' || category === 'Wake' || isCertificate;
    document.getElementById('documentRequirementsNote').style.display = noDocsService ? 'none' : 'block';
    uploadGroup.style.display = noDocsService ? 'none' : 'block';
    document.getElementById('extraDocumentsInput').disabled = noDocsService;
    // ALWAYS clear old requirement rows first — prevents stale required inputs
    // from a previous service (e.g. Funeral's "Death Certificate") from
    // blocking a different service (e.g. Wedding) via hidden required fields.
    document.getElementById('requirementRows').innerHTML = '';
    if (!noDocsService) rebuildRequirementRows(serviceId);

    var policyBox = document.getElementById('policyBox');
    if (POLICIES[category]) {
      policyBox.style.display = 'block';
      policyBox.innerHTML = 'ℹ ' + POLICIES[category];
    } else {
      policyBox.style.display = 'none';
    }

    // Regular/Special toggle, only for the 4 admin-configurable categories.
    var scheduleTypeGroup = document.getElementById('scheduleTypeGroup');
    scheduleTypeGroup.style.display = usesToggle ? 'block' : 'none';
    var badge = document.getElementById('scheduleTypeBadge');
    if (usesToggle) {
      badge.textContent = scheduleType;
      badge.className = 'badge ' + (scheduleType === 'Regular' ? 'badge-regular' : 'badge-special');
    } else {
      badge.textContent = '';
    }

    var freeGroup = document.getElementById('freeTimeGroup');
    var fixedGroup = document.getElementById('fixedTimeGroup');
    var massGroup = document.getElementById('massTimeGroup');
    var regularGroup = document.getElementById('regularSlotGroup');
    var dateTimeRow = document.getElementById('dateTimeRow');
    var freeInput = document.getElementById('freeTimeInput');
    var fixedHidden = document.getElementById('fixedTimeInput');
    var massHidden = document.getElementById('massTimeInput');
    var regularDateHidden = document.getElementById('regularDateInput');
    var regularTimeHidden = document.getElementById('regularTimeInput');
    var dateInput = document.getElementById('appointmentDateInput');

    freeGroup.style.display = 'none'; freeInput.disabled = true;
    fixedGroup.style.display = 'none'; fixedHidden.disabled = true;
    massGroup.style.display = 'none'; massHidden.disabled = true;
    regularGroup.style.display = 'none'; regularDateHidden.disabled = true; regularTimeHidden.disabled = true;
    dateTimeRow.style.display = 'flex'; dateInput.disabled = false; dateInput.required = true;

    var specialMsgBox = document.getElementById('specialSchedulingMessage');
    specialMsgBox.style.display = 'none';

    if (category === 'Confirmation' || category === 'First Communion') {
      dateTimeRow.style.display = 'none';
      dateInput.disabled = true;
      dateInput.required = false;

      // Hide Priest selector
      var priestGroup = document.getElementById('priestFieldGroup');
      var priestSelect = document.getElementById('priestSelect');
      priestGroup.style.display = 'none';
      priestSelect.disabled = true;
    } else if (category === 'Funeral') {
      dateTimeRow.style.display = 'none';
      dateInput.disabled = true;
      dateInput.required = false;

      // Funeral shows Priest selector (parish priest or Others for visiting priest)
      var priestGroup = document.getElementById('priestFieldGroup');
      var priestSelect = document.getElementById('priestSelect');
      priestGroup.style.display = 'block';
      priestSelect.disabled = false;
    } else if (isMassIntention) {
      massGroup.style.display = 'block';
      massHidden.disabled = false;
      autoAssignMassTime();
      var priestGroup = document.getElementById('priestFieldGroup');
      var priestSelect = document.getElementById('priestSelect');
      priestGroup.style.display = 'none';
      priestSelect.disabled = true;
      priestSelect.value = '';
    } else if (usesToggle && scheduleType === 'Regular') {
      dateTimeRow.style.display = 'none';
      dateInput.disabled = true;
      dateInput.required = false;
      regularGroup.style.display = 'block';
      regularDateHidden.disabled = false;
      regularTimeHidden.disabled = false;
      initRegularCalendar(serviceId);
    } else {
      // Special mode for toggle categories, or free-form categories like House Blessing.
      freeGroup.style.display = 'block';
      freeInput.disabled = false;
      updateOccupiedTimesHint();
    }

    updateFuneralScheduleUI();
    updateOutsidePriestUI();
    refreshAvailability();
  }

  /**
   * Regular-schedule date picker — a small dropdown calendar attached to a
   * single date field (like a native date-input picker), not a big
   * always-open grid. Only dates matching this service's configured weekly
   * schedule are clickable; every other date is dimmed but its number still
   * shows. The matching time is fixed and shown read-only once a date is
   * picked; there is no way to choose a different time. Re-created per
   * service since the available dates differ by service.
   */
  var regularAvailableByDate = {};
  var regularAutoJumped = false; // one-shot per calendar open — never fights a manual prev/next click
  var regularViewYear = 0;
  var regularViewMonth = 0; // 0-11
  var regularServiceId = null;
  var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  function initRegularCalendar(serviceId) {
    var hint = document.getElementById('regularSlotHint');
    var fixedBox = document.getElementById('regularFixedTimeBox');
    fixedBox.style.display = 'none';
    document.getElementById('regularDateInput').value = '';
    document.getElementById('regularTimeInput').value = '';
    document.getElementById('regularDpFieldText').textContent = 'mm/dd/yyyy';
    document.getElementById('regularDpFieldText').className = 'mini-dp-placeholder';
    regularAvailableByDate = {};
    regularAutoJumped = false;
    regularServiceId = serviceId;
    hint.textContent = 'Loading available dates…';

    var now = new Date();
    regularViewYear = now.getFullYear();
    regularViewMonth = now.getMonth();
    fetchRegularMonth(serviceId, regularYearMonth());
    renderRegularDpGrid();
  }

  function regularYearMonth() {
    return regularViewYear + '-' + String(regularViewMonth + 1).padStart(2, '0');
  }

  function todayStr() {
    var now = new Date();
    return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
  }

  /** Renders the 6x7 day grid for the currently-viewed month (regularViewYear/regularViewMonth). */
  function renderRegularDpGrid() {
    var grid = document.getElementById('regularDpGrid');
    var title = document.getElementById('regularDpTitle');
    title.textContent = MONTH_NAMES[regularViewMonth] + ' ' + regularViewYear;

    var firstOfMonth = new Date(regularViewYear, regularViewMonth, 1);
    var startWeekday = firstOfMonth.getDay(); // 0 = Sunday
    var daysInMonth = new Date(regularViewYear, regularViewMonth + 1, 0).getDate();
    var daysInPrevMonth = new Date(regularViewYear, regularViewMonth, 0).getDate();
    var selected = document.getElementById('regularDateInput').value;
    var today = todayStr();

    var cells = [];
    // Leading days from the previous month (dimmed, not interactive).
    for (var i = startWeekday - 1; i >= 0; i--) {
      cells.push({ day: daysInPrevMonth - i, outside: true });
    }
    for (var d = 1; d <= daysInMonth; d++) {
      var dateStr = regularYearMonth() + '-' + String(d).padStart(2, '0');
      cells.push({ day: d, outside: false, dateStr: dateStr });
    }
    var trailing = 42 - cells.length;
    for (var n = 1; n <= trailing; n++) {
      cells.push({ day: n, outside: true });
    }

    grid.innerHTML = cells.map(function (c) {
      if (c.outside) {
        return '<span class="mini-dp-day mini-dp-outside">' + c.day + '</span>';
      }
      var available = Object.prototype.hasOwnProperty.call(regularAvailableByDate, c.dateStr);
      var classes = 'mini-dp-day';
      if (!available) classes += ' mini-dp-disabled';
      if (c.dateStr === selected) classes += ' mini-dp-selected';
      if (c.dateStr === today) classes += ' mini-dp-today';
      return '<span class="' + classes + '" data-date="' + c.dateStr + '"' + (available ? '' : ' aria-disabled="true"') + '>' + c.day + '</span>';
    }).join('');
  }

  function selectRegularDate(dateStr) {
    if (!Object.prototype.hasOwnProperty.call(regularAvailableByDate, dateStr)) return;
    var time = regularAvailableByDate[dateStr];
    var fixedBox = document.getElementById('regularFixedTimeBox');
    document.getElementById('regularDateInput').value = dateStr;
    document.getElementById('regularTimeInput').value = time;
    document.getElementById('regularFixedTimeText').textContent = formatTimeLabel(time);
    fixedBox.style.display = 'block';

    var fieldText = document.getElementById('regularDpFieldText');
    var d = new Date(dateStr + 'T00:00:00');
    fieldText.textContent = String(d.getMonth() + 1).padStart(2, '0') + '/' + String(d.getDate()).padStart(2, '0') + '/' + d.getFullYear()
      + ' — ' + formatTimeLabel(time);
    fieldText.className = '';

    document.getElementById('regularSlotHint').textContent = 'Selected. Click a different highlighted date to change it.';
    renderRegularDpGrid();
    closeRegularDpPopup();
    refreshAvailability();
  }

  function openRegularDpPopup() {
    document.getElementById('regularDpPopup').style.display = 'block';
  }
  function closeRegularDpPopup() {
    document.getElementById('regularDpPopup').style.display = 'none';
  }

  function fetchRegularMonth(serviceId, yearMonth) {
    var hint = document.getElementById('regularSlotHint');
    fetch(CHECK_AVAILABILITY_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ service_id: serviceId, category: '', schedule_type: 'Regular', month: yearMonth })
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        regularAvailableByDate = data.regular_month_dates || {};
        renderRegularDpGrid();
        var count = Object.keys(regularAvailableByDate).length;

        if (count) {
          hint.textContent = 'Highlighted dates are available for this schedule — pick one.';
          return;
        }

        var nextMonth = data.regular_next_available_month;
        if (nextMonth && !regularAutoJumped) {
          // First time this calendar has come up empty since it was opened —
          // jump straight to the nearest month that actually has an opening,
          // instead of leaving the parishioner staring at an all-gray month
          // with no clue "next" needs clicking (possibly many times, for a
          // sparse schedule like "4th Saturday only").
          regularAutoJumped = true;
          hint.textContent = 'No openings this month — jumping to the next available month…';
          var parts = nextMonth.split('-');
          regularViewYear = parseInt(parts[0], 10);
          regularViewMonth = parseInt(parts[1], 10) - 1;
          renderRegularDpGrid();
          fetchRegularMonth(serviceId, nextMonth);
        } else if (nextMonth) {
          hint.innerHTML = 'No available dates this month. <a href="#" id="regularJumpLink">Jump to the next available month</a>, or choose Special instead.';
          var link = document.getElementById('regularJumpLink');
          if (link) {
            link.addEventListener('click', function (e) {
              e.preventDefault();
              var p = nextMonth.split('-');
              regularViewYear = parseInt(p[0], 10);
              regularViewMonth = parseInt(p[1], 10) - 1;
              renderRegularDpGrid();
              fetchRegularMonth(serviceId, nextMonth);
            });
          }
        } else {
          hint.textContent = 'No available dates found for this schedule in the next 12 months. Please choose Special instead, or contact the parish office.';
        }
      })
      .catch(function () {
        hint.textContent = 'Could not load available dates — please try again.';
      });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var offeringAmount = document.getElementById('offeringAmount');
    if (offeringAmount) {
      // Do not let an accidental mouse-wheel/trackpad scroll change the amount.
      offeringAmount.addEventListener('wheel', function (event) {
        if (document.activeElement === offeringAmount) event.preventDefault();
      }, { passive: false });
    }
    document.getElementById('regularDpField').addEventListener('click', openRegularDpPopup);
    document.getElementById('regularDpField').addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openRegularDpPopup(); }
    });
    document.getElementById('regularDpPrev').addEventListener('click', function () {
      regularViewMonth--;
      if (regularViewMonth < 0) { regularViewMonth = 11; regularViewYear--; }
      renderRegularDpGrid();
      fetchRegularMonth(regularServiceId, regularYearMonth());
    });
    document.getElementById('regularDpNext').addEventListener('click', function () {
      regularViewMonth++;
      if (regularViewMonth > 11) { regularViewMonth = 0; regularViewYear++; }
      renderRegularDpGrid();
      fetchRegularMonth(regularServiceId, regularYearMonth());
    });
    document.getElementById('regularDpGrid').addEventListener('click', function (e) {
      var cell = e.target.closest('.mini-dp-day:not(.mini-dp-outside):not(.mini-dp-disabled)');
      if (cell) selectRegularDate(cell.dataset.date);
    });
    document.getElementById('regularDpClear').addEventListener('click', function (e) {
      e.preventDefault();
      document.getElementById('regularDateInput').value = '';
      document.getElementById('regularTimeInput').value = '';
      document.getElementById('regularFixedTimeBox').style.display = 'none';
      var fieldText = document.getElementById('regularDpFieldText');
      fieldText.textContent = 'mm/dd/yyyy';
      fieldText.className = 'mini-dp-placeholder';
      renderRegularDpGrid();
      refreshAvailability();
    });
    document.getElementById('regularDpToday').addEventListener('click', function (e) {
      e.preventDefault();
      var now = new Date();
      regularViewYear = now.getFullYear();
      regularViewMonth = now.getMonth();
      renderRegularDpGrid();
      fetchRegularMonth(regularServiceId, regularYearMonth());
    });
    document.addEventListener('click', function (e) {
      var wrap = document.getElementById('regularMiniDp');
      if (wrap && !wrap.contains(e.target)) closeRegularDpPopup();
    });
  });

  /**
   * Live pre-submit check: re-validates the currently selected date/time via
   * the server's scheduling rules and refreshes which priests are available.
   * Advisory only — book.php re-runs the same checks before actually saving.
   */
  function refreshAvailability() {
    var select = document.getElementById('serviceSelect');
    var selectedOption = select.options[select.selectedIndex];
    var category = selectedOption ? selectedOption.dataset.category || '' : '';
    var serviceId = selectedOption ? selectedOption.value : '';
    if (!category) return;

    var effective = getEffectiveDateTime(category);
    var msgBox = document.getElementById('availabilityMsg');
    var submitBtn = document.getElementById('bookSubmitBtn');
    // Never re-enable the button while a booking is being submitted ("Submitting…").
    function setSubmitBlocked(blocked) {
      if (submitBtn.getAttribute('aria-busy') !== 'true') submitBtn.disabled = blocked;
    }

    if (!effective.date || !effective.time) {
      msgBox.style.display = 'none';
      setSubmitBlocked(false);
      return;
    }

    var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;
    var payload = {
      service_id: serviceId,
      category: category,
      schedule_type: category === 'Blessing' ? 'Special' : (usesToggle ? getScheduleType() : null),
      appointment_date: effective.date,
      appointment_time: effective.time,
      priest_id: document.getElementById('priestSelect').value || null,
      date_of_death: document.getElementById('dateOfDeathInput').value || null
    };

    fetch(CHECK_AVAILABILITY_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.valid === false) {
          msgBox.textContent = '⚠ ' + data.message;
          msgBox.style.display = 'block';
          setSubmitBlocked(true);
        } else {
          msgBox.style.display = 'none';
          setSubmitBlocked(false);
        }
        rebuildPriestOptions(data.available_priests || []);
      })
      .catch(function () { /* Advisory check only — submit still re-validates server-side. */ });
  }

  /** Reads the date+time currently in effect for the selected category/mode. */
  function getEffectiveDateTime(category) {
    var isMassIntention = category === 'Mass Intention';
    var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;

    if (category === 'Funeral') {
      return { date: document.getElementById('appointmentDateInput').value, time: document.getElementById('fixedTimeInput').value };
    }
    if (isMassIntention) {
      return { date: document.getElementById('appointmentDateInput').value, time: document.getElementById('massTimeInput').value };
    }
    if (usesToggle && getScheduleType() === 'Regular') {
      return { date: document.getElementById('regularDateInput').value, time: document.getElementById('regularTimeInput').value };
    }
    return { date: document.getElementById('appointmentDateInput').value, time: document.getElementById('freeTimeInput').value };
  }

  /** Rebuilds the priest <select> as Available/Unavailable optgroups from a check-availability.php response. */
  function rebuildPriestOptions(priests) {
    var select = document.getElementById('priestSelect');
    if (select.disabled || !priests.length) return;
    var current = select.value;

    var html = '<option value="">No preference</option>';
    var available = priests.filter(function (p) { return p.available; });
    var unavailable = priests.filter(function (p) { return !p.available; });

    if (available.length) {
      html += '<optgroup label="Available">' + available.map(function (p) {
        return '<option value="' + p.priest_id + '">' + p.label + '</option>';
      }).join('') + '</optgroup>';
    }
    if (unavailable.length) {
      html += '<optgroup label="Unavailable">' + unavailable.map(function (p) {
        return '<option value="' + p.priest_id + '" disabled title="' + (p.reason || '').replace(/"/g, '&quot;') + '">' + p.label + ' — Unavailable' + (p.note ? ' (' + p.note.replace(/</g, '&lt;') + ')' : '') + '</option>';
      }).join('') + '</optgroup>';
    }
    var sSelect = document.getElementById('serviceSelect');
    var category = sSelect.options[sSelect.selectedIndex]?.dataset.category || '';
    if (category !== '' && category !== 'Donation' && category !== 'Mass Intention' && category !== 'Certificate') {
      html += '<option value="other" id="priestOtherOption"' + (current === 'other' ? ' selected' : '') + '>Other (Preferred Priest)</option>';
    }
    _cachedPriestOptionsHtml = html;
    select.innerHTML = html;

    // Keep the parishioner's selection unless it just became unavailable.
    if (current === 'other') {
      select.value = 'other';
    } else {
      var stillAvailable = available.some(function (p) { return String(p.priest_id) === current; });
      select.value = stillAvailable ? current : '';
    }
    updateOutsidePriestUI();
  }

  /**
   * Builds one file-upload row (with a live status pill) per parsed service
   * requirement. Each gets its own uniquely-named field (req_doc_0, req_doc_1,
   * ...) rather than a shared documents[] array, so the server can match each
   * upload to its requirement by field name alone — no fragile positional
   * pairing with a parallel labels array.
   */
  function renderRequirementsPreview(serviceId, category) {
    var docList = document.getElementById('previewDocumentsList');
    var formList = document.getElementById('previewFormsList');
    var items = REQUIREMENTS_BY_SERVICE[serviceId] || [];

    var docs = [];
    var forms = [];

    // Base forms based on category
    if (category === 'Wedding') {
      forms.push('Marriage Requirement and Application Form');
      forms.push('Katin-awan sa Kasal / Cluster Clearance');
      forms.push('Wedding Sponsor Clearance');
    } else if (category === 'Baptism') {
      forms.push('Katin-awan sa Bunyag');
      forms.push('Cluster Clearance for Baptism Sponsor');
    } else if (category === 'Funeral') {
      forms.push('Katin-awan sa Paglubong');
    }

    // Filter requirements from DB
    items.forEach(function (item) {
      if (item === 'Katin-awan sa Paglubong') {
        // Already handled in base forms
        return;
      }
      if (category === 'Wedding' && PRECANA.enabled && item === PRECANA.certificateLabel) {
        docs.push(item + ' (upload after your Pre-Cana seminar)');
        return;
      }
      docs.push(item);
    });

    // Render Docs
    if (docs.length > 0) {
      document.getElementById('previewSupportingDocuments').style.display = 'block';
      docList.innerHTML = docs.map(function (d) {
        return '<li style="margin-bottom:4px;">' + d + '</li>';
      }).join('');
    } else {
      document.getElementById('previewSupportingDocuments').style.display = 'none';
    }

    // Render Forms
    if (forms.length > 0) {
      document.getElementById('previewFormsToComplete').style.display = 'block';
      formList.innerHTML = forms.map(function (f) {
        return '<li style="margin-bottom:4px;">' + f + '</li>';
      }).join('');
    } else {
      document.getElementById('previewFormsToComplete').style.display = 'none';
    }
  }

  function rebuildRequirementRows(serviceId) {
    var container = document.getElementById('requirementRows');
    var items = REQUIREMENTS_BY_SERVICE[serviceId] || [];
    container.innerHTML = items.map(function (label, i) {
      if (label === 'Katin-awan sa Paglubong') {
        return '<div class="form-group doc-req-row">' +
          '<label>' + label + ' <span style="color:var(--danger);">*</span> <span class="badge badge-pending doc-status-pill">Generated Form</span></label>' +
          '<p class="helper-text" style="margin-top:0;">You will fill out this form on the next screen after booking.</p>' +
          '<input type="hidden" name="req_doc_' + i + '_generated" value="1">' +
          '</div>';
      }
      var optionalIfMarried = /\(\s*if\s+married\s*\)/i.test(label);
      var displayLabel = label.replace(/\s*\(\s*if\s+married\s*\)/i, '');
      return (
        '<div class="form-group doc-req-row">' +
        '<label>' + displayLabel + (optionalIfMarried ? ' <span class="badge badge-pending doc-status-pill">Optional — only if parents are married</span>' : ' <span style="color:var(--danger);">*</span> <span class="badge badge-rejected doc-status-pill">Required</span>') + '</label>' +
        '<input type="file" name="req_doc_' + i + '" accept=".pdf,.jpg,.jpeg,.png"' + (optionalIfMarried ? '' : ' required') + ' data-label="' + label.replace(/"/g, '&quot;') + '">' +
        '</div>'
      );
    }).join('');

    container.querySelectorAll('input[type="file"]').forEach(function (input) {
      input.addEventListener('change', function () { checkRequirementFile(input); });
    });
  }

  /** Client-side pre-check (extension/size/portrait) — advisory; book.php is authoritative. */
  function checkRequirementFile(input) {
    var pill = input.closest('.doc-req-row').querySelector('.doc-status-pill');
    var file = input.files[0];

    if (!file) {
      pill.textContent = 'Missing';
      pill.className = 'badge badge-rejected doc-status-pill';
      return;
    }

    pill.textContent = 'Checking…';
    pill.className = 'badge badge-pending doc-status-pill';

    var allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
    var ext = file.name.split('.').pop().toLowerCase();
    if (allowedExt.indexOf(ext) === -1) {
      pill.textContent = 'Invalid';
      pill.className = 'badge badge-rejected doc-status-pill';
      return;
    }

    if (ext === 'pdf') {
      pill.textContent = 'Valid';
      pill.className = 'badge badge-pending doc-status-pill';
      return;
    }

    checkImagePortrait(file, function (ok) {
      if (ok) {
        pill.textContent = 'Valid';
        pill.className = 'badge badge-pending doc-status-pill';
      } else {
        pill.textContent = 'Invalid orientation';
        pill.className = 'badge badge-rejected doc-status-pill';
        pill.title = 'Please upload this document in portrait orientation.';
      }
    });
  }

  /** The parish's three official Sunday Mass Intention times (mirrors MASS_INTENTION_TIMES in includes/scheduling.php). */
  var MASS_INTENTION_TIMES = [['06:30', '1st Mass'], ['09:00', '2nd Mass'], ['16:30', '3rd Mass']];

  /** Returns the available Mass Intention times for a given date (mirrors massIntentionTimesForDate()). */
  function massTimesForDate(dateStr) {
    var dow = new Date(dateStr + 'T00:00:00').getDay();
    if (dow === 0) return MASS_INTENTION_TIMES; // Sunday: three Masses
    if (dow === 3) return [['17:15', 'Afternoon Mass']]; // Wednesday: 5:15 PM
    return [['06:00', 'Daily Mass']]; // all other days: 6:00 AM
  }

  /**
   * Mass Intentions can only be booked at the three official Masses — the
   * parishioner picks one from this dropdown (no free time entry). Each time
   * is checked against the server's availability rules for the chosen date;
   * unavailable/occupied ones are shown disabled with the reason.
   */
  function autoAssignMassTime() {
    var dateInput = document.getElementById('appointmentDateInput');
    var select = document.getElementById('massTimeSelect');
    var hidden = document.getElementById('massTimeInput');
    var hint = document.getElementById('massTimeHint');
    var requestedDate = dateInput.value;

    if (!requestedDate) {
      select.innerHTML = '<option value="">Select a date first</option>';
      hidden.value = '';
      hint.textContent = 'Select a date to see the available Mass times.';
      return;
    }

    var previous = hidden.value;
    select.innerHTML = '<option value="">Checking available Mass times…</option>';
    hidden.value = '';

    function render(slots, checked) {
      if (dateInput.value !== requestedDate) return; // date changed while loading
      var anyAvailable = slots.some(function (s) { return s.available; });
      select.innerHTML = '<option value="">-- Select a Mass time --</option>' + slots.map(function (s) {
        return s.available
          ? '<option value="' + s.time + '">' + s.label + '</option>'
          : '<option value="' + s.time + '" disabled>' + s.label + ' — Unavailable' + (s.reason ? ' (' + s.reason + ')' : '') + '</option>';
      }).join('');
      var keep = slots.some(function (s) { return s.available && s.time === previous; });
      select.value = keep ? previous : '';
      hidden.value = select.value;
      hint.textContent = !checked
        ? 'Choose one of the parish\'s Mass times. Availability is confirmed when you submit.'
        : (anyAvailable ? (new Date(requestedDate + 'T00:00:00').getDay() === 0 ? 'Sunday Masses: 6:00 AM, 9:00 AM and 4:00 PM. Unavailable times are greyed out.' : 'Monday to Saturday there is one Daily Mass at 6:00 AM.') : 'No Mass times are available on this date — please choose another date.');
      refreshAvailability();
    }

    fetch(CHECK_AVAILABILITY_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ category: 'Mass Intention', appointment_date: requestedDate })
    })
      .then(function (res) { return res.json(); })
      .then(function (data) { render(data.mass_slots || [], true); })
      .catch(function () {
        render(massTimesForDate(requestedDate).map(function (t) {
          return { time: t[0], label: formatTimeLabel(t[0]) + ' — ' + t[1], available: true, reason: null };
        }), false);
      });
  }

  /** Submit-button wording: Mass Intentions are paid as part of submitting. */
  function submitButtonLabel() {
    var select = document.getElementById('serviceSelect');
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    var draftMode = document.getElementById('draftModeInput');
    if (draftMode) draftMode.value = ['Wedding', 'Baptism', 'Funeral'].indexOf(category) !== -1 ? '1' : '0';
    // Legacy fallback removed, handled cleanly by main event loop
    if (category === 'Wedding') return 'Continue to Wedding Requirements';
    if (category === 'Baptism') return 'Continue to Baptism Requirements';
    if (category === 'Funeral') return 'Continue to Funeral Requirements';
    return category === 'Mass Intention' ? 'Pay & Submit Mass Intention' : 'Submit Appointment Request';
  }

  function updatePayModeUI() {
    // no-op: manual pay mode removed from Mass Intentions; kept for safety
  }

  /** For free-choice date/time entry (First Communion, or Special mode) — show which times that date is already occupied by a scheduled Mass. */
  function updateOccupiedTimesHint() {
    var dateInput = document.getElementById('appointmentDateInput');
    var hint = document.getElementById('occupiedTimesHint');
    if (!dateInput.value) {
      hint.textContent = '';
    } else {
      var times = massTimesForJS(dateInput.value).map(formatTimeLabel);
      hint.textContent = 'Mass is scheduled on this date at: ' + times.join(', ') + '. You can still book these times; priests saying Mass then are shown as unavailable, so choose a different priest.';
    }
    refreshAvailability();
  }

  /**
   * Funeral: the family's preferred burial / Funeral Mass date and time (one
   * schedule). Shown, enabled and required for Funeral only; the date picker
   * starts after the date of death (and never before today). The Parish Office
   * confirms the final schedule.
   */
  function updateFuneralScheduleUI() {
    var row = document.getElementById('funeralScheduleRow');
    if (!row) return;
    var select = document.getElementById('serviceSelect');
    var isFuneral = (select.options[select.selectedIndex]?.dataset.category || '') === 'Funeral';
    var dateInput = document.getElementById('funeralPreferredDateInput');
    var timeInput = document.getElementById('funeralPreferredTimeInput');
    row.style.display = isFuneral ? 'flex' : 'none';
    [dateInput, timeInput].forEach(function (el) { el.disabled = !isFuneral; el.required = isFuneral; });
    var deathDate = document.getElementById('dateOfDeathInput').value;
    var dayAfterDeath = deathDate ? addDaysJS(deathDate, 1) : '';
    dateInput.min = dayAfterDeath && dayAfterDeath > todayStr() ? dayAfterDeath : todayStr();
  }

  /** Funeral preferred schedule rules the browser cannot express with attributes (the server re-checks). */
  function funeralScheduleBeforeCheck() {
    var row = document.getElementById('funeralScheduleRow');
    if (!row) return;
    var dateInput = document.getElementById('funeralPreferredDateInput');
    var timeInput = document.getElementById('funeralPreferredTimeInput');
    dateInput.setCustomValidity('');
    timeInput.setCustomValidity('');
    if (row.style.display === 'none') return;
    var deathDate = document.getElementById('dateOfDeathInput').value;
    var date = dateInput.value;
    var time = timeInput.value;
    var now = new Date();
    var nowTime = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
    if (date) {
      if (date < todayStr()) dateInput.setCustomValidity('The preferred date cannot be in the past.');
      else if (deathDate && date <= deathDate) dateInput.setCustomValidity('The preferred burial / Funeral Mass date must be after the date of death.');
      else if (new Date(date + 'T00:00:00').getDay() === 2) dateInput.setCustomValidity('The parish office is closed all day Tuesday (staff day off). Please choose another date.');
    }
    if (time) {
      if (!/:(00|30)$/.test(time)) timeInput.setCustomValidity('Please choose a time in 30-minute intervals (for example 9:00 or 9:30).');
      else if (date && new Date(date + 'T00:00:00').getDay() === 1 && time >= '12:00') timeInput.setCustomValidity('The parish office is closed every Monday afternoon (staff day off). Please choose a morning time or another date.');
      else if (date && date === todayStr() && time <= nowTime) timeInput.setCustomValidity('Please choose a time that is still ahead.');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('serviceSelect').addEventListener('change', toggleServiceUI);
    document.getElementById('scheduleTypeRegular').addEventListener('change', toggleServiceUI);
    document.getElementById('scheduleTypeSpecial').addEventListener('change', toggleServiceUI);
    var priestSelect = document.getElementById('priestSelect');
    var btnAddAnotherPriest = document.getElementById('btnAddAnotherPriest');
    var outsideGroup = document.getElementById('outsidePriestGroup');
    var outsideInput = document.getElementById('outsidePriestInput');

    if (priestSelect) {
      priestSelect.addEventListener('change', function () {
        if (this.value === 'other') {
          if (outsideGroup) outsideGroup.style.display = 'block';
          if (outsideInput) { outsideInput.disabled = false; outsideInput.focus(); }
        } else {
          if (outsideGroup) outsideGroup.style.display = 'none';
          if (outsideInput) { outsideInput.value = ''; outsideInput.disabled = true; outsideInput.setCustomValidity(''); }
        }
        refreshAvailability();
      });
    }

    if (btnAddAnotherPriest) {
      btnAddAnotherPriest.addEventListener('click', function () {
        var row0Select = document.getElementById('priestSelect');
        var row0Group = document.getElementById('outsidePriestGroup');
        var row0Input = document.getElementById('outsidePriestInput');
        if (row0Select && (row0Select.value === '' || row0Select.value === null) && (!row0Group || row0Group.style.display === 'none')) {
          row0Select.value = 'other';
          if (row0Group) row0Group.style.display = 'block';
          if (row0Input) { row0Input.disabled = false; row0Input.focus(); }
        } else {
          addPriestRow(true);
        }
      });
    }

    if (outsideInput) {
      outsideInput.addEventListener('blur', function () {
        this.value = this.value.replace(/^(?:rev(?:erend)?\.?\s*(?:fr\.?|father)?|fr\.?|father)\s+/i, '').trim();
      });
    }

    document.getElementById('dateOfDeathInput').addEventListener('change', function () {
      updateFuneralScheduleUI();
      refreshAvailability();
    });
    document.getElementById('appointmentDateInput').addEventListener('change', function () {
      var select = document.getElementById('serviceSelect');
      var category = select.options[select.selectedIndex]?.dataset.category || '';
      if (category === 'Mass Intention') autoAssignMassTime();
      else updateOccupiedTimesHint();
    });
    document.getElementById('freeTimeInput').addEventListener('change', refreshAvailability);
    var funeralDate = document.getElementById('funeralPreferredDateInput');
    var funeralTime = document.getElementById('funeralPreferredTimeInput');
    if (funeralDate) {
      funeralDate.addEventListener('change', function () {
        funeralScheduleBeforeCheck();
        refreshAvailability();
      });
    }
    if (funeralTime) {
      funeralTime.addEventListener('change', function () {
        funeralScheduleBeforeCheck();
        refreshAvailability();
      });
    }
    var precanaDate = document.getElementById('precanaDateInput');
    var precanaTime = document.getElementById('precanaTimeInput');
    if (precanaDate) precanaDate.addEventListener('change', precanaBeforeCheck);
    if (precanaTime) precanaTime.addEventListener('change', precanaBeforeCheck);

    // Auto-compose the hidden guest_name from the split first/last/middle fields
    var guestLast = document.getElementById('guestLastNameInput');
    var guestFirst = document.getElementById('guestFirstNameInput');
    var guestMiddle = document.getElementById('guestMiddleNameInput');
    var guestCombined = document.getElementById('guestCombinedName');
    if (guestLast && guestFirst && guestCombined) {
      function syncGuestName() {
        var last = guestLast.value.trim().toUpperCase();
        var first = guestFirst.value.trim().toUpperCase();
        var mid = guestMiddle ? guestMiddle.value.trim().toUpperCase() : '';
        guestCombined.value = last + ', ' + first + (mid ? ' ' + mid : '');
      }
      [guestLast, guestFirst, guestMiddle].filter(Boolean).forEach(function (el) {
        el.addEventListener('input', syncGuestName);
      });
      syncGuestName();
    }

    // Automatically transform all text inputs and textareas to uppercase on typing in real-time
    document.getElementById('bookForm').addEventListener('input', function (e) {
      var el = e.target;
      if (!el || el.type === 'email' || el.type === 'password' || el.type === 'file' || el.type === 'date' || el.type === 'time' || el.type === 'hidden') return;
      if ((el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'search' || !el.type)) || el.tagName === 'TEXTAREA') {
        var start = el.selectionStart;
        var end = el.selectionEnd;
        var upper = el.value.toUpperCase();
        if (el.value !== upper) {
          el.value = upper;
          if (start !== null && end !== null) {
            try { el.setSelectionRange(start, end); } catch (err) {}
          }
        }
      }
    });

    document.getElementById('massTimeSelect').addEventListener('change', function () {
      document.getElementById('massTimeInput').value = this.value;
      refreshAvailability();
    });

    // Inline errors under each field (required, phone, date and age rules, Mass slot/offering).
    attachInlineValidation(document.getElementById('bookForm'), { beforeCheck: bookingBeforeCheck });

    // Submit Booking: one request at a time. The button shows one steady
    // "Submitting…" with a spinner (same size) from the click until the next
    // page has loaded — the server's redirect to Services, a draft's
    // requirements step, or PayMongo. An invalid form never gets here: the
    // inline validation (capture phase) stops it first, so the button stays enabled.
    var bookSubmitting = false;
    function setBookSubmitting(on) {
      var btn = document.getElementById('bookSubmitBtn');
      bookSubmitting = on;
      btn.disabled = on;
      if (on) {
        btn.style.minWidth = btn.offsetWidth + 'px';
        btn.setAttribute('aria-busy', 'true');
        btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>Submitting…</span>';
      } else {
        btn.removeAttribute('aria-busy');
        btn.style.minWidth = '';
        btn.textContent = submitButtonLabel();
      }
    }
    // Back from PayMongo or a draft page (page restored from the browser's cache): usable again.
    window.addEventListener('pageshow', function (e) { if (e.persisted && bookSubmitting) setBookSubmitting(false); });

    document.getElementById('bookForm').addEventListener('submit', function (e) {
      e.preventDefault();
      if (bookSubmitting) return; // a second click / Enter while the first request is still on its way
      var submitBtn = document.getElementById('bookSubmitBtn');
      var errorBox = document.getElementById('bookFormError');
      errorBox.style.display = 'none';

      // Ensure all text inputs and textareas are uppercase before submission
      document.querySelectorAll('#bookForm input[type="text"], #bookForm textarea').forEach(function (el) {
        if (el.type !== 'email' && el.type !== 'password' && el.type !== 'file' && el.type !== 'date' && el.type !== 'time' && el.type !== 'hidden') {
          el.value = el.value.toUpperCase();
        }
      });

      // Compose guest full name before submission
      if (guestCombined && typeof syncGuestName === 'function') syncGuestName();

      var serviceSelect = document.getElementById('serviceSelect');
      var category = serviceSelect.options[serviceSelect.selectedIndex]?.dataset.category || '';

      setBookSubmitting(true);

      var formData = new FormData(e.target);
      fetch('<?= url('parishioner/book.php') ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then(function (res) {
          return res.text().then(function (rawText) {
            var data;
            try {
              data = JSON.parse(rawText);
            } catch (err) {
              console.error('Non-JSON response from server:', rawText);
              var clean = rawText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
              if (clean && clean.length < 250) {
                return { success: false, message: clean };
              }
              return { success: false, message: 'Server returned an unexpected response. Please refresh the page and try again.' };
            }
            return data;
          });
        })
        .then(function (data) {
          if (data.success && data.redirect) {
            // Submitted: nothing left to restore if the person comes back to this page.
            if (data.thank_you) writeBookingSnapshot(null);
            // Saved (Services shows the Thank You), a draft's requirements step, or
            // PayMongo's hosted checkout (a Mass Intention only counts once paid).
            // The button keeps "Submitting…" until that page loads.
            window.location.href = data.redirect;
            return;
          }
          setBookSubmitting(false);
          if (data.success) {
            if (data.redirect) {
              window.location.href = data.redirect;
              return;
            }
            writeBookingSnapshot(null); // submitted: nothing left to restore on Back
            document.getElementById('bookFormView').style.display = 'none';
            document.getElementById('bookConfirmView').style.display = 'block';
            document.getElementById('bookConfirmMessage').textContent = data.documents_reminder || '';
            var detailLink = document.getElementById('bookConfirmDetailLink');
            var refCode = data.tracking_number || data.guest_reference || '';
            document.getElementById('bookConfirmReferenceCode').textContent = refCode;
            if (!data.is_guest && data.detail_url) {
              detailLink.style.display = '';
              detailLink.href = data.detail_url;
            } else {
              detailLink.style.display = 'none';
            }
            var typeLine = document.getElementById('bookConfirmScheduleType');
            if (data.schedule_type) {
              typeLine.textContent = data.schedule_type + ' Schedule';
              typeLine.className = 'badge ' + (data.schedule_type === 'Regular' ? 'badge-regular' : 'badge-special');
              typeLine.style.display = 'inline-block';
            } else {
              typeLine.style.display = 'none';
            }
          } else {
            errorBox.textContent = data.message || 'Unable to process your request. Please try again.';
            errorBox.style.display = 'block';
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          }
        })
        .catch(function (err) {
          // No clear answer (connection dropped, or an unexpected reply): the
          // request may still have been saved, so don't just say "try again".
          setBookSubmitting(false);
          console.error('Booking submission error:', err);
          var customMsg = (err && err.message && err.message.length < 250 && !err.message.includes('JSON')) ? err.message : null;
          errorBox.textContent = customMsg || <?= json_encode($identity['is_guest']
            ? 'We could not confirm whether your request was received. Before submitting again, please open Check Status: if it went through, your Thank You and reference code will appear there.'
            : 'We could not confirm whether your request was received. Before submitting again, please reload this page or check My Appointments: if it went through, your Thank You and reference code will appear.') ?>;
          errorBox.style.display = 'block';
          errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
  });

  function currentScheduleSelection() {
    var date = document.getElementById('regularDateInput');
    var time = document.getElementById('regularTimeInput');
    if (date && date.disabled === false) {
      return { date: date.value, time: time.value };
    }
    // For special/mass schedule if used in Funeral
    var massTime = document.getElementById('massTimeInput');
    if (massTime && massTime.disabled === false) {
      // date might be regularDateInput or similar depending on implementation
      return { date: date ? date.value : '', time: massTime.value };
    }
    return { date: '', time: '' };
  }

  // "Back to Booking Form": reopen the form as soon as the DOM is ready. This
  // listener is registered after every other DOMContentLoaded handler above, so
  // the form's change listeners already exist; waiting for pageshow instead
  // would hold the form back until every font, image and CDN script loaded.
  document.addEventListener('DOMContentLoaded', function () {
    if (RESUME_DRAFT) { resumeDraftBooking(); RESUME_DRAFT = null; resumedFromDraft = true; }
  });

  // =====================================================================
  // Booking Wizard Stepper
  // =====================================================================
  var _bookCurrentStep = 1;

  function bookStepUpdateIndicator(step) {
    for (var i = 1; i <= 3; i++) {
      var el = document.getElementById('bstep-' + i);
      if (!el) continue;
      el.classList.remove('active', 'done');
      if (i < step) el.classList.add('done');
      else if (i === step) el.classList.add('active');
      var circle = document.getElementById('bstep-circle-' + i);
      if (circle) circle.innerHTML = (i < step) ? '&#10003;' : i;
    }
    for (var j = 1; j <= 2; j++) {
      var conn = document.getElementById('bstep-conn-' + j);
      if (conn) {
        if (j < step) conn.classList.add('done');
        else conn.classList.remove('done');
      }
    }
  }

  function bookStepPopulateReview() {
    var serviceSelect = document.getElementById('serviceSelect');
    var opt = serviceSelect ? serviceSelect.options[serviceSelect.selectedIndex] : null;
    var serviceName = opt ? opt.textContent.trim() : '—';
    var category = opt ? (opt.dataset.category || '') : '';

    var dateVal = document.getElementById('appointmentDateInput') ? document.getElementById('appointmentDateInput').value : '';
    var timeVal = '';
    var fixedBox = document.getElementById('regularFixedTimeBox');
    if (fixedBox && fixedBox.style.display !== 'none') {
      var fixedTxt = document.getElementById('regularFixedTimeText');
      if (fixedTxt) timeVal = fixedTxt.textContent.trim();
    }
    if (!timeVal) {
      var massTime = document.getElementById('massTimeSelect');
      if (massTime && massTime.offsetParent !== null && massTime.value) {
        timeVal = massTime.options[massTime.selectedIndex]?.textContent || massTime.value;
      }
    }
    if (!timeVal) {
      var freeTime = document.getElementById('freeTimeInput');
      if (freeTime && freeTime.offsetParent !== null && freeTime.value) {
        timeVal = freeTime.options ? (freeTime.options[freeTime.selectedIndex]?.textContent || freeTime.value) : freeTime.value;
      }
    }
    var schedText = dateVal ? (dateVal + (timeVal ? ' at ' + timeVal : '')) : 'To be confirmed';
    if (category === 'Funeral') {
      var burialDate = document.getElementById('funeralPreferredDateInput')?.value;
      var burialTimeEl = document.getElementById('funeralPreferredTimeInput');
      var burialTime = burialTimeEl?.options ? (burialTimeEl.options[burialTimeEl.selectedIndex]?.textContent || burialTimeEl.value) : burialTimeEl?.value;
      if (burialDate) {
        schedText = 'Preferred: ' + burialDate + (burialTime ? ' at ' + burialTime : '');
      } else {
        schedText = 'To be confirmed';
      }
    } else if (category === 'Confirmation' || category === 'First Communion') {
      schedText = 'To be scheduled by parish office';
    }

    var elService = document.getElementById('reviewService');
    if (elService) elService.textContent = serviceName;
    var elSched = document.getElementById('reviewSchedule');
    if (elSched) elSched.textContent = schedText;

    var detailsList = [];
    if (['Baptism', 'Wedding', 'Funeral'].indexOf(category) !== -1) {
      var pssSelect = document.getElementById('pssClaimInput');
      if (pssSelect && pssSelect.value) {
        detailsList.push(pssSelect.value === 'pss' ? 'PSS: PSS Giver (Subject to verification)' : 'PSS: Non-PSS Giver');
      }
    }
    if (category === 'Mass Intention') {
      var offerer = document.getElementById('offererNameInput')?.value.trim();
      var intentionFor = document.getElementById('intentionForInput')?.value.trim();
      var purpose = document.getElementById('massPurposeSelect')?.value;
      var offering = document.getElementById('offeringAmount')?.value;
      if (offerer) detailsList.push('Offered by: ' + offerer);
      if (intentionFor) detailsList.push('Intention for: ' + intentionFor);
      if (purpose) detailsList.push('Purpose: ' + purpose);
      if (offering) detailsList.push('Offering: ₱' + Number(offering).toLocaleString());
    } else if (category === 'Funeral') {
      var deceased = document.getElementById('patientNameInput')?.value.trim();
      var dod = document.getElementById('dateOfDeathInput')?.value;
      if (deceased) detailsList.push('Deceased: ' + deceased);
      if (dod) detailsList.push('Date of Death: ' + dod);
    } else if (category === 'Anointing') {
      var sick = document.getElementById('patientNameInput')?.value.trim();
      var addr = document.getElementById('anointingAddressInput')?.value.trim();
      if (sick) detailsList.push('Patient: ' + sick);
      if (addr) detailsList.push('Address: ' + addr);
    } else if (category === 'Blessing') {
      var bAddr = document.getElementById('blessingAddressInput')?.value.trim();
      if (bAddr) detailsList.push('Location: ' + bAddr);
    } else if (category === 'Certificate') {
      var certType = document.getElementById('certificateTypeSelect')?.value;
      if (certType) detailsList.push('Certificate: ' + certType);
      detailsList.push('Fee: ₱100.00');
      var req = document.getElementById('certRequestorInput')?.value.trim();
      if (req && (certType === 'Baptismal Certificate' || certType === 'Confirmation Certificate' || certType === 'Death Certificate')) {
        detailsList.push('Requestor: ' + req);
      }
      if (certType === 'Baptismal Certificate') {
        var reg = document.getElementById('certBaptismRegistrantInput')?.value.trim();
        var birth = document.getElementById('certBaptismBirthInput')?.value.trim();
        var byear = document.getElementById('certBaptismYearInput')?.value.trim();
        if (reg) detailsList.push('Registrant: ' + reg);
        if (birth) detailsList.push('Birth: ' + birth);
        if (byear) detailsList.push('Baptism Year: ' + byear);
      } else if (certType === 'Confirmation Certificate') {
        var reg = document.getElementById('certConfirmRegistrantInput')?.value.trim();
        var birth = document.getElementById('certConfirmBirthInput')?.value.trim();
        var cyear = document.getElementById('certConfirmYearInput')?.value.trim();
        if (reg) detailsList.push('Registrant: ' + reg);
        if (birth) detailsList.push('Birth: ' + birth);
        if (cyear) detailsList.push('Confirmation Year: ' + cyear);
      } else if (certType === 'Death Certificate') {
        var dec = document.getElementById('certDeceasedNameInput')?.value.trim();
        var dyear = document.getElementById('certDeathYearInput')?.value.trim();
        if (dec) detailsList.push('Deceased: ' + dec);
        if (dyear) detailsList.push('Death Year: ' + dyear);
      } else if (certType === 'Marriage Certificate') {
        var groom = document.getElementById('certGroomNameInput')?.value.trim();
        var bride = document.getElementById('certBrideNameInput')?.value.trim();
        var myear = document.getElementById('certMarriageYearInput')?.value.trim();
        if (groom) detailsList.push('Groom: ' + groom);
        if (bride) detailsList.push('Bride: ' + bride);
        if (myear) detailsList.push('Marriage Year: ' + myear);
      }
    }

    var preferredPriestsList = [];
    var priestRows = document.querySelectorAll('#priestRowsContainer .priest-row');
    priestRows.forEach(function (row) {
      var sel = row.querySelector('.priest-select');
      if (!sel || sel.offsetParent === null) return;
      if (sel.value === 'other') {
        var outsideInput = row.querySelector('.outside-priest-input');
        var outside = outsideInput ? outsideInput.value.trim() : '';
        if (outside) {
          var clean = outside.replace(/^(?:rev(?:erend)?\.?\s*(?:fr\.?|father)?|fr\.?|father)\s+/i, '').trim();
          if (clean) preferredPriestsList.push('Fr. ' + clean);
        }
      } else if (sel.value) {
        var priestName = sel.options[sel.selectedIndex]?.textContent.trim();
        if (priestName && priestName !== 'No preference') preferredPriestsList.push(priestName);
      }
    });
    if (preferredPriestsList.length > 0) {
      detailsList.push((preferredPriestsList.length > 1 ? 'Preferred Priests: ' : 'Preferred Priest: ') + preferredPriestsList.join(', '));
    }

    var detailsWrap = document.getElementById('reviewDetailsWrap');
    var elDetails = document.getElementById('reviewDetails');
    if (detailsWrap && elDetails) {
      if (detailsList.length > 0) {
        detailsWrap.style.display = 'block';
        elDetails.innerHTML = detailsList.map(function (d) {
          return '<span style="display:inline-block; margin-right:8px; margin-bottom:4px; background:rgba(0,0,0,0.05); padding:2px 8px; border-radius:4px;">' + d + '</span>';
        }).join('');
      } else {
        detailsWrap.style.display = 'none';
      }
    }
  }

  function bookStepShow(step) {
    _bookCurrentStep = step;
    for (var i = 1; i <= 3; i++) {
      var panel = document.getElementById('book-panel-' + i);
      if (panel) panel.classList.toggle('active', i === step);
    }
    bookStepUpdateIndicator(step);

    if (step === 2) {
      var uploadGroup = document.getElementById('uploadGroup');
      var docNote = document.getElementById('documentRequirementsNote');
      var previewBox = document.getElementById('requirementsPreviewBox');
      var hasDocs = (uploadGroup && uploadGroup.style.display !== 'none') ||
                    (docNote && docNote.style.display !== 'none') ||
                    (previewBox && previewBox.style.display !== 'none');
      var emptyMsg = document.getElementById('step2-empty-msg');
      if (emptyMsg) emptyMsg.style.display = hasDocs ? 'none' : 'block';
    }

    if (step === 3) {
      bookStepPopulateReview();
    }

    var modalBody = document.querySelector('#bookModal .modal-body');
    if (modalBody) modalBody.scrollTop = 0;
  }

  function bookStepValidate(step) {
    var panel = document.getElementById('book-panel-' + step);
    if (!panel) return true;
    if (step === 1 && typeof bookingBeforeCheck === 'function') {
      bookingBeforeCheck();
    }
    if (step === 1) {
      var priestRows = document.querySelectorAll('#priestRowsContainer .priest-row');
      for (var p = 0; p < priestRows.length; p++) {
        var row = priestRows[p];
        var sel = row.querySelector('.priest-select');
        if (sel && sel.value === 'other') {
          var pInput = row.querySelector('.outside-priest-input');
          if (pInput && !pInput.value.trim()) {
            pInput.setCustomValidity('Please enter the name of your Preferred Priest or remove this field.');
            pInput.reportValidity();
            pInput.focus();
            return false;
          } else if (pInput) {
            pInput.setCustomValidity('');
          }
        }
      }
    }
    var inputs = panel.querySelectorAll('input, select, textarea');
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      if (el.disabled || el.type === 'hidden' || el.offsetParent === null) continue;
      if (!el.checkValidity()) {
        el.reportValidity();
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
      }
    }
    return true;
  }

  function bookStepGo(step) {
    bookStepShow(step);
  }

  function bookStepNext(fromStep) {
    if (!bookStepValidate(fromStep)) return;
    if (fromStep === 1) {
      bookStepShow(2);
    } else if (fromStep === 2) {
      bookStepShow(3);
    }
  }

  function bookStepReset() {
    bookStepShow(1);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('bookForm');
    if (form) {
      form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
          if (_bookCurrentStep < 3) {
            e.preventDefault();
            bookStepNext(_bookCurrentStep);
          }
        }
      });
    }
  });

</script>

<?php include __DIR__ . '/../includes/' . ($identity['is_guest'] ? 'public-shell-end.php' : 'dash-end.php'); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>