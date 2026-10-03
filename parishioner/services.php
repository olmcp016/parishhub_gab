<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduling.php';
$identity = requireParishionerOrGuest();

$services = db()->query("SELECT * FROM services WHERE is_active = 1 AND category != 'Donation' ORDER BY category, service_name")->fetchAll();
$variableFeeCategories = ['Baptism', 'Wedding'];
$feeRulesByCategory = [];
try {
    $feeRuleRows = db()->query("SELECT * FROM service_fee_rules ORDER BY service_category, schedule_type, pss_classification")->fetchAll();
    foreach ($feeRuleRows as $feeRule) $feeRulesByCategory[$feeRule['service_category']][] = $feeRule;
} catch (Throwable $e) {
    // The pricing migration is deployed separately; legacy services remain usable until then.
}
$donationEnabled = db()->query("SELECT setting_value FROM settings WHERE setting_key = 'donation_enabled'")->fetchColumn() !== '0';
$priests = db()->query("SELECT * FROM priests WHERE status = 'active'")->fetchAll();
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
    $requirementsByService[$s['service_id']] = parseRequirementsList($s['requirements']);
    if ($s['category'] === 'Wedding') {
        $requirementsByService[$s['service_id']] = [
            'Baptismal Certificate',
            'Confirmation Certificate',
            "Sponsors' Baptismal Certificate"
        ];
    }
}
foreach (['Mass Intention', 'Funeral', 'First Communion'] as $cat) {
    if (!isset($policies[$cat])) {
        $policies[$cat] = schedulingPolicyText($cat);
    }
}

// These 2 categories offer a Regular (parish-fixed slot) vs Special
// (custom date/time, availability-checked) choice — see includes/scheduling.php.
$scheduleToggleCategories = ['Baptism', 'Wedding'];

$active = 'services';
$pageTitle = 'Available Services';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/' . ($identity['is_guest'] ? 'public-shell-start.php' : 'dash-start.php');
?>

<?php if ($identity['is_guest']): ?>
  <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); margin-bottom:18px;">
    You're browsing as a guest — no account needed to book. <a href="<?= url('auth/register.php') ?>">Create a free account</a> to track your requests in one place, or continue below and save your confirmation reference code.
  </div>
<?php endif; ?>

<div class="grid-3">
  <?php foreach ($services as $s): ?>
    <?php $isMassIntention = $s['category'] === 'Mass Intention'; ?>
    <div class="card" style="display:flex; flex-direction:column;">
      <h3><?= e($s['service_name']) ?></h3>
      <p class="text-muted" style="font-size:12.5px; text-transform:uppercase; letter-spacing:.4px;"><?= e($s['category']) ?></p>
      <p style="font-size:14px;"><?= e($s['description']) ?></p>
      <?php if (in_array($s['category'], ['Baptism', 'Wedding'], true) && !empty($feeRulesByCategory[$s['category']])): ?>
        <p class="text-muted" style="font-size:13px; margin-bottom:4px;">Regular and Special fees available by PSS classification.</p>
        <button type="button" class="btn btn-secondary btn-sm" style="align-self:flex-start; margin-bottom:12px;" onclick="document.getElementById('fees-<?= strtolower($s['category']) ?>').showModal()">View Fees</button>
      <?php endif; ?>
      <?php if ($s['requirements']): ?>
        <details class="requirements-toggle" style="margin-bottom:12px;">
          <summary>Requirements</summary>
          <p><?= e($s['requirements']) ?></p>
        </details>
      <?php endif; ?>
      <div style="margin-top:auto; padding-top:16px;">
        <div style="margin-bottom:12px;">
          <span class="text-gold" style="font-weight:700; font-size:14px; display:block;">
            <?= in_array($s['category'], $variableFeeCategories, true) ? 'Fee varies by schedule and PSS status' : feeLabel((float) $s['fee']) ?>
          </span>
        </div>
        <button type="button" class="btn btn-primary btn-block" style="width:100%;" onclick="openBookModal(<?= $s['service_id'] ?>)"><?= $isMassIntention ? 'Enter Intentions' : 'Book Now' ?></button>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($donationEnabled): ?>
    <div class="card" style="background: linear-gradient(135deg, var(--cream-dark), #faf0d0); display:flex; flex-direction:column;">
      <h3>🤲 Donate to Our Parish</h3>
      <p class="text-muted" style="font-size:12.5px; text-transform:uppercase; letter-spacing:.4px;">Donation</p>
      <p style="font-size:14px;">Support our ministries and services with a voluntary offering — any amount is welcome.</p>
      <div style="margin-top:auto; padding-top:16px;">
        <div style="margin-bottom:12px;">
          <span class="text-gold" style="font-weight:700; font-size:14px; display:block;">Voluntary</span>
        </div>
        <a href="<?= url('parishioner/donations.php?donate=1') ?>" class="btn btn-primary btn-block" style="width:100%; text-align:center;">Donate Now</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php foreach (['Baptism', 'Wedding'] as $feeCategory): ?>
  <?php if (!empty($feeRulesByCategory[$feeCategory])): ?>
    <?php $feeService = array_values(array_filter($services, fn($x) => $x['category'] === $feeCategory))[0] ?? null; ?>
    <?php if ($feeService): ?><dialog class="modal" id="fees-<?= strtolower($feeCategory) ?>">
      <div class="modal-head"><h3><?= e($feeCategory) ?> Service Fees</h3><button type="button" class="modal-close" onclick="this.closest('dialog').close()">×</button></div>
      <div class="modal-body">
        <?php foreach (['Regular', 'Special'] as $feeSchedule): ?>
          <h4><?= e($feeSchedule) ?> Schedule</h4>
          <div class="table-wrap"><table><thead><tr><th></th><th>PSS Giver</th><th>Non-PSS Giver</th></tr></thead><tbody>
          <?php $pssRule = array_values(array_filter($feeRulesByCategory[$feeCategory], fn($r) => $r['schedule_type'] === $feeSchedule && $r['pss_classification'] === 'pss'))[0] ?? null; $nonRule = array_values(array_filter($feeRulesByCategory[$feeCategory], fn($r) => $r['schedule_type'] === $feeSchedule && $r['pss_classification'] === 'non_pss'))[0] ?? null; ?>
          <?php if ($pssRule && $nonRule): ?><tr><td>Base Fee</td><td><?= feeLabel((float) $pssRule['base_fee']) ?></td><td><?= feeLabel((float) $nonRule['base_fee']) ?></td></tr><tr><td>Priest Stipend</td><td><?= feeLabel((float) $pssRule['priest_stipend']) ?></td><td><?= feeLabel((float) $nonRule['priest_stipend']) ?></td></tr><tr><td>Additional Sponsor</td><td><?= feeLabel((float) $pssRule['additional_sponsor_fee']) ?> each after <?= (int) $pssRule['included_sponsors'] ?></td><td><?= feeLabel((float) $nonRule['additional_sponsor_fee']) ?> each after <?= (int) $nonRule['included_sponsors'] ?></td></tr><?php endif; ?>
          </tbody></table></div>
        <?php endforeach; ?>
      </div>
    </dialog><?php endif; ?>
  <?php endif; ?>
<?php endforeach; ?>

<!-- ===================== Booking Modal ===================== -->
<dialog class="modal modal-lg" id="bookModal">
  <div class="modal-head">
    <h3 id="bookModalTitle">Book a Service</h3>
    <button type="button" class="modal-close" onclick="closeBookModal()">✕</button>
  </div>
  <div class="modal-body">

    <div id="bookFormView">
      <div id="policyBox" class="alert" style="display:none; background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark);"></div>

      <form method="POST" action="<?= url('parishioner/book.php') ?>" enctype="multipart/form-data" id="bookForm">
        <?= csrfField() ?>
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="draft_mode" id="draftModeInput" value="0">

        <?php if ($identity['is_guest']): ?>
        <style>
          .guest-info-fields {
            display: flex;
            flex-direction: column;
            gap: 16px;
          }
          .guest-info-fields .form-group {
            width: 100%;
            margin-bottom: 0;
          }
        </style>
        <div style="background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--cream-dark);">
          <h4 style="margin-top:0; margin-bottom:12px;">Guest Information</h4>
          <div class="guest-info-fields">
            <div class="form-group">
              <label>First Name</label>
              <input type="text" name="guest_firstname" id="guestFirstNameInput" required placeholder="Juan" style="width:100%;">
            </div>
            <div class="form-group">
              <label>Middle Name <span class="text-muted" style="font-weight:400;">(optional)</span></label>
              <input type="text" name="guest_middlename" id="guestMiddleNameInput" placeholder="Santos" style="width:100%;">
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input type="text" name="guest_lastname" id="guestLastNameInput" required placeholder="Dela Cruz" style="width:100%;">
            </div>
            <div class="form-group">
              <label>Phone Number</label>
              <input type="tel" name="guest_phone" id="guestPhoneInput" required pattern="09[0-9]{9}" maxlength="11" minlength="11" placeholder="09XXXXXXXXX" title="Must be exactly 11 digits starting with 09" style="width:100%;">
            </div>
            <div class="form-group">
              <label>Email Address <span class="text-muted" style="font-weight:400;">(optional)</span></label>
              <input type="email" name="guest_email" id="guestEmailInput" placeholder="you@example.com" style="width:100%;">
            </div>
          </div>
          <input type="hidden" name="guest_name" id="guestCombinedName">
          <p class="helper-text" style="margin-top:12px; margin-bottom:0;">We'll use your name and phone number to identify your request — you'll get a reference code to check its status anytime.</p>
        </div>
        <?php endif; ?>

        <div class="form-group">
          <label>Select Service</label>
          <select name="service_id" id="serviceSelect" required>
            <option value="">-- Choose a service --</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= $s['service_id'] ?>" data-category="<?= e($s['category']) ?>">
                <?= e($s['service_name']) ?> (<?= in_array($s['category'], $variableFeeCategories, true) ? 'Fee varies by schedule/PSS status' : feeLabel((float) $s['fee']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="blessingFields" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">House Blessing Details</h4>
          <?php if (!$identity['is_guest']): ?>
            <div class="form-group">
              <label>Contact Phone Number</label>
              <input type="tel" name="contact_phone" id="blessingPhoneInput" value="<?= e($profilePhone) ?>" placeholder="09XX XXX XXXX">
            </div>
          <?php endif; ?>
          <div class="form-group">
            <label>Address / Location to Bless</label>
            <textarea name="location_address" id="blessingAddressInput" rows="2" placeholder="House number, street, barangay..."><?= e($profileAddress) ?></textarea>
            <p class="helper-text">This is just the address of the home/establishment — no document upload needed.</p>
          </div>
        </div>

        <div id="dateOfDeathGroup" class="form-group" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Date of Death</label>
          <input type="date" name="date_of_death" id="dateOfDeathInput" max="<?= date('Y-m-d') ?>">
          <p class="helper-text" id="earliestFuneralHint"></p>
        </div>

        <div class="form-group" id="scheduleTypeGroup" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
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

        <div id="feeClassificationGroup" class="form-group" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>PSS Classification Claim</label>
          <select name="pss_claim" id="pssClaimInput"><option value="">-- Select --</option><option value="pss">PSS Giver (subject to verification)</option><option value="non_pss">Non-PSS Giver</option></select>
          <p class="helper-text">The parish Secretary verifies this classification before the final fee is charged.</p>
        </div>
        <div id="sponsorCountGroup" class="form-group" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Number of Sponsors</label><input type="number" class="numeric-no-spinner numeric-zero-friendly" name="sponsor_count" id="sponsorCountInput" min="0" max="100" step="1" value="0">
        </div>
        <div id="weddingSponsorCountGroup" class="form-group" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px;">
          <label>Number of Individual Sponsors</label><input type="number" class="numeric-no-spinner numeric-zero-friendly" name="wedding_sponsor_count" id="weddingSponsorCountInput" min="0" max="200" step="1" value="0"><p class="helper-text">The first 4 individual sponsors (2 pairs) are included; each additional individual sponsor is ₱100.</p>
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
          <div id="regularFixedTimeBox" style="display:none; margin-top:8px; background: var(--cream); border-radius: 8px; padding: 10px 12px;">
            <strong>Time:</strong> <span id="regularFixedTimeText"></span> <span class="text-muted">(fixed for this schedule)</span>
          </div>
          <input type="hidden" name="appointment_date" id="regularDateInput" disabled>
          <input type="hidden" name="appointment_time" id="regularTimeInput" disabled>
        </div>

        <div class="form-row" id="dateTimeRow">
          <div class="form-group">
            <label>Preferred Date</label>
            <input type="date" name="appointment_date" id="appointmentDateInput" required min="<?= date('Y-m-d') ?>" value="<?= e($preselectedDate) ?>">
          </div>
          <div class="form-group">
            <label>Preferred Time</label>

            <div id="freeTimeGroup">
              <input type="time" name="appointment_time" id="freeTimeInput" step="1800">
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

        <div id="specialSchedulingMessage" class="alert" style="display:none; background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); margin-bottom:18px;">
        </div>


        <div id="availabilityMsg" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <div class="form-group" id="priestFieldGroup">
          <label>Preferred Priest (optional)</label>
          <select name="priest_id" id="priestSelect">
            <option value="">No preference</option>
            <?php foreach ($priests as $p): ?>
              <option value="<?= $p['priest_id'] ?>"><?= e($p['title']) ?> <?= e($p['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="helper-text">Choosing a specific priest is subject to his availability — in case of unavoidable priest emergencies or pastoral duties, the parish office reserves the right to assign an available priest.</p>
        </div>

        <div id="intentionFields" style="display:none; background: var(--cream); padding: 14px; border-radius: 8px; margin-bottom: 16px;">
          <h4 style="margin-top:0;">Mass Intention Details</h4>
          <p class="helper-text" style="margin-top:-4px;">No documents needed. You choose the offering amount. Online payments are verified through PayMongo; cash offerings remain pending until the Cashier confirms them.</p>
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
              <label>Offerer Name</label>
              <input type="text" name="offerer_name" id="offererNameInput" placeholder="Your full name">
            </div>
          </div>
          <div class="form-group">
            <label>Intention For</label>
            <input type="text" name="intention_for" id="intentionForInput" placeholder="Name(s) the mass is offered for">
          </div>
          <div class="form-group">
            <label>Prayer Message (optional)</label>
            <textarea name="message" rows="2"></textarea>
          </div>

          <h4 style="margin-bottom:6px;">Offering &amp; Payment</h4>
          <div class="form-group">
            <label>Offering Amount (₱) — must be more than ₱0</label>
            <input type="number" name="amount" id="offeringAmount" class="amount-no-spinner" min="0.01" step="0.01" placeholder="e.g. 500" inputmode="decimal">
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

        <div class="form-group">
          <label>Additional Remarks</label>
          <textarea name="remarks" rows="2" placeholder="Any special requests..."></textarea>
        </div>

        <div class="form-group" id="documentRequirementsNote" style="display:none;">
          <label>Required Documents</label>
          <div class="alert" style="background: var(--cream); color: var(--brown-mid); border: 1px solid var(--cream-dark); font-size:13px; margin-bottom:12px;">
            <strong>Document Requirements:</strong>
            <ul style="margin:6px 0 0; padding-left:18px;">
              <li>Please upload a clear and readable document.</li>
              <li>Document must be in portrait orientation.</li>
              <li>Make sure all information is visible.</li>
              <li>Do not upload blurry, corrupted, or incorrect files.</li>
              <li>Upload the required document as a PDF, JPG, or PNG file.</li>
            </ul>
            <p style="margin:8px 0 0;">These automated checks confirm a file is readable and correctly formatted — they do not verify authenticity. Our parish staff will do a final manual review before approval.</p>
          </div>
        </div>

        <div class="form-group" id="uploadGroup">
          <label>Required Documents</label>

          <div id="requirementRows"></div>

          <div class="form-group" style="margin-top:10px;">
            <label>Additional Documents (optional)</label>
            <input type="file" name="documents[]" id="extraDocumentsInput" multiple accept=".pdf,.jpg,.jpeg,.png">
            <p class="helper-text">Max <?= e(ini_get('upload_max_filesize')) ?> per file, <?= e(ini_get('post_max_size')) ?> total for the whole form. If your photos are larger than that (common for phone camera photos), you can also upload documents later from the appointment detail page instead.</p>
          </div>
        </div>

        <div id="weddingFormsPreview" class="form-group" style="display:none; margin-top:16px;">
          <label>Wedding Forms</label>
          <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">
            These required forms are completed in the next step after your booking details and supporting documents are saved.
          </div>
          <div class="wedding-form-requirement-list">
            <p><strong>Marriage Requirement and Application Form</strong><br><span class="badge badge-rejected">Required</span> <span class="helper-text">Complete in Wedding Requirements</span></p>
            <p><strong>Katin-awan sa Kasal / Cluster Clearance</strong><br><span class="badge badge-rejected">Required</span> <span class="helper-text">Complete in Wedding Requirements</span></p>
            <p><strong>Wedding Sponsor Clearance</strong><br><span class="badge badge-rejected">Required</span> <span class="helper-text">Complete in Wedding Requirements</span></p>
          </div>
        </div>
        <div id="baptismFormsPreview" class="form-group" style="display:none; margin-top:16px;">
          <label>Baptism Forms</label>
          <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">These required forms are completed after your booking details and supporting documents are saved.</div>
          <div class="wedding-form-requirement-list">
            <p><strong>Katin-awan sa Bunyag</strong><br><span class="badge badge-rejected">Required</span> <span class="helper-text">Complete in Baptism Requirements</span></p>
            <p><strong>Cluster Clearance for Baptism Sponsor</strong><br><span class="badge badge-rejected">Required</span> <span class="helper-text">Complete in Baptism Requirements</span></p>
          </div>
        </div>
        <div id="funeralFormsPreview" class="form-group" style="display:none; margin-top:16px;">
          <label>Funeral Forms</label>
          <div class="alert" style="background:var(--cream); color:var(--brown-mid); border:1px solid var(--cream-dark);">These required forms are completed after your booking details and supporting documents are saved.</div>
          <div class="wedding-form-requirement-list">
            <p><strong>Katin-awan sa Paglubong</strong><br><span class="badge badge-rejected">Required</span> <span class="helper-text">Complete in Funeral Requirements</span></p>
          </div>
        </div>

        <div id="bookFormError" class="alert" style="display:none; background: var(--danger-bg); color: var(--danger); border: 1px solid #f5c2c2;"></div>

        <button type="submit" class="btn btn-primary btn-block" id="bookSubmitBtn">Submit Appointment Request</button>
      </form>
    </div>

    <div id="bookConfirmView" style="display:none; text-align:center; padding: 20px 10px;">
      <div style="font-size:48px; margin-bottom:12px;">✔</div>
      <h3 style="margin:0 0 10px;">Request Submitted!</h3>
      <p id="bookConfirmScheduleType" style="display:none; margin: -4px 0 10px;"></p>
      <p id="bookConfirmMessage" style="color: var(--brown-mid); margin-bottom:20px;"></p>
      <div id="bookConfirmReferenceBox" style="display:none; background: var(--cream); border: 1px solid var(--cream-dark); border-radius: 10px; padding: 14px; margin-bottom:20px;">
        <p class="helper-text" style="margin:0 0 6px;">Your reference code — save this to check your request's status anytime:</p>
        <p style="font-size:22px; font-weight:700; letter-spacing:1px; color: var(--brown-dark); margin:0;" id="bookConfirmReferenceCode"></p>
      </div>
      <div class="flex gap-3" style="justify-content:center; flex-wrap:wrap;">
        <a href="#" id="bookConfirmDetailLink" class="btn btn-outline">View Appointment</a>
        <button type="button" class="btn btn-primary" onclick="closeBookModal()">Done</button>
      </div>
    </div>

  </div>
</dialog>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js"></script>
<script src="<?= url('public/js/calendar.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/calendar.js') ?>"></script>
<script src="<?= url('public/js/scheduling.js') ?>"></script>
<script>
var POLICIES = <?= json_encode($policies, JSON_UNESCAPED_UNICODE) ?>;
var CALENDAR_BLOCKED = <?= json_encode($calendarBlocked, JSON_UNESCAPED_UNICODE) ?>;
var REQUIREMENTS_BY_SERVICE = <?= json_encode($requirementsByService, JSON_UNESCAPED_UNICODE) ?>;
var SCHEDULE_TOGGLE_CATEGORIES = <?= json_encode($scheduleToggleCategories) ?>;
var CHECK_AVAILABILITY_URL = <?= json_encode(url('parishioner/check-availability.php')) ?>;

function openBookModal(serviceId) {
  var modal = document.getElementById('bookModal');
  document.getElementById('bookFormView').style.display = 'block';
  document.getElementById('bookConfirmView').style.display = 'none';
  document.getElementById('bookFormError').style.display = 'none';
  document.getElementById('bookForm').reset();

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
  modal.showModal();
}

function closeBookModal() {
  document.getElementById('bookModal').close();
}

function getScheduleType() {
  return document.getElementById('scheduleTypeSpecial').checked ? 'Special' : 'Regular';
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
  document.getElementById('sponsorCountGroup').style.display = category === 'Baptism' ? 'block' : 'none';
  document.getElementById('weddingSponsorCountGroup').style.display = category === 'Wedding' ? 'block' : 'none';
  document.getElementById('sponsorCountInput').required = category === 'Baptism';
  document.getElementById('weddingSponsorCountInput').required = category === 'Wedding';
  document.getElementById('weddingFormsPreview').style.display = category === 'Wedding' ? 'block' : 'none';
  document.getElementById('baptismFormsPreview').style.display = category === 'Baptism' ? 'block' : 'none';
  document.getElementById('funeralFormsPreview').style.display = category === 'Funeral' ? 'block' : 'none';
  document.getElementById('bookSubmitBtn').textContent = submitButtonLabel();

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

  var isBlessing = category === 'Blessing';
  document.getElementById('blessingFields').style.display = isBlessing ? 'block' : 'none';
  document.getElementById('blessingAddressInput').required = isBlessing;
  var blessingPhone = document.getElementById('blessingPhoneInput');
  if (blessingPhone) blessingPhone.required = isBlessing;

  // Priests do not personally read Mass Intentions, so there's nothing to prefer.
  var priestGroup = document.getElementById('priestFieldGroup');
  var priestSelect = document.getElementById('priestSelect');
  priestGroup.style.display = isMassIntention ? 'none' : 'block';
  priestSelect.disabled = isMassIntention;
  if (isMassIntention) priestSelect.value = '';

  // No documents are required for Mass Intentions — they're approved instantly.
  var uploadGroup = document.getElementById('uploadGroup');
  var draftService = category === 'Baptism' || category === 'Wedding';
  document.getElementById('documentRequirementsNote').style.display = isMassIntention ? 'none' : 'block';
  uploadGroup.style.display = isMassIntention || draftService ? 'none' : 'block';
  document.getElementById('extraDocumentsInput').disabled = isMassIntention || draftService;
  // ALWAYS clear old requirement rows first — prevents stale required inputs
  // from a previous service (e.g. Funeral's "Death Certificate") from
  // blocking a different service (e.g. Wedding) via hidden required fields.
  document.getElementById('requirementRows').innerHTML = '';
  if (!isMassIntention && !draftService) rebuildRequirementRows(serviceId);

  var policyBox = document.getElementById('policyBox');
  if (POLICIES[category]) {
    policyBox.style.display = 'block';
    policyBox.textContent = 'ℹ ' + POLICIES[category];
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

  if (category === 'Confirmation' || category === 'First Communion' || category === 'Funeral' || category === 'Wake') {
    dateTimeRow.style.display = 'none';
    dateInput.disabled = true;
    dateInput.required = false;
    
    // Hide Priest selector
    var priestGroup = document.getElementById('priestFieldGroup');
    var priestSelect = document.getElementById('priestSelect');
    priestGroup.style.display = 'none';
    priestSelect.disabled = true;
    
    specialMsgBox.style.display = 'block';
    if (category === 'Confirmation') {
      specialMsgBox.textContent = "Schedule will be arranged by the parish office based on the Bishop's availability.";
    } else if (category === 'First Communion') {
      specialMsgBox.textContent = "First Communion is scheduled during February. The parish office will assign the final date and time.";
    } else if (category === 'Funeral') {
      specialMsgBox.textContent = "Funeral schedule will be arranged by the parish office after reviewing your documents.";
    } else if (category === 'Wake') {
      specialMsgBox.textContent = "Wake and Death Anniversary schedules are arranged at the parish office.";
    }
  } else if (isMassIntention) {
    massGroup.style.display = 'block';
    massHidden.disabled = false;
    autoAssignMassTime();
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

  updateEarliestFuneralHint();
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

  if (!effective.date || !effective.time) {
    msgBox.style.display = 'none';
    submitBtn.disabled = false;
    return;
  }

  var usesToggle = SCHEDULE_TOGGLE_CATEGORIES.indexOf(category) !== -1;
  var payload = {
    service_id: serviceId,
    category: category,
    schedule_type: usesToggle ? getScheduleType() : null,
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
        submitBtn.disabled = true;
      } else {
        msgBox.style.display = 'none';
        submitBtn.disabled = false;
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
  select.innerHTML = html;

  // Keep the parishioner's selection unless it just became unavailable.
  var stillAvailable = available.some(function (p) { return String(p.priest_id) === current; });
  select.value = stillAvailable ? current : '';
}

/**
 * Builds one file-upload row (with a live status pill) per parsed service
 * requirement. Each gets its own uniquely-named field (req_doc_0, req_doc_1,
 * ...) rather than a shared documents[] array, so the server can match each
 * upload to its requirement by field name alone — no fragile positional
 * pairing with a parallel labels array.
 */
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
    return (
      '<div class="form-group doc-req-row">' +
        '<label>' + label + ' <span style="color:var(--danger);">*</span> <span class="badge badge-rejected doc-status-pill">Required</span></label>' +
        '<input type="file" name="req_doc_' + i + '" accept=".pdf,.jpg,.jpeg,.png" required data-label="' + label.replace(/"/g, '&quot;') + '">' +
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

/** The parish's three official Mass Intention times (mirrors MASS_INTENTION_TIMES in includes/scheduling.php). */
var MASS_INTENTION_TIMES = [['06:00', '1st Mass'], ['09:00', '2nd Mass'], ['16:00', '3rd Mass']];

/** Sundays have the three Masses; Monday–Saturday there is only the 6:00 AM Daily Mass (mirrors massIntentionTimesForDate()). */
function massTimesForDate(dateStr) {
  var d = new Date(dateStr + 'T00:00:00');
  return d.getDay() === 0 ? MASS_INTENTION_TIMES : [['06:00', 'Daily Mass']];
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
  if (draftMode) draftMode.value = ['Wedding', 'Baptism'].indexOf(category) !== -1 ? '1' : '0';
  var baptismPreview = document.getElementById('baptismFormsPreview');
  if (baptismPreview) baptismPreview.style.display = category === 'Baptism' ? 'block' : 'none';
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
    hint.textContent = 'Occupied by Mass on this date: ' + times.join(', ') + '. Please choose a different time.';
  }
  refreshAvailability();
}

function updateEarliestFuneralHint() {
  var select = document.getElementById('serviceSelect');
  var category = select.options[select.selectedIndex]?.dataset.category || '';
  var hint = document.getElementById('earliestFuneralHint');
  var dodInput = document.getElementById('dateOfDeathInput');
  var dateInput = document.getElementById('appointmentDateInput');

  if (category !== 'Funeral' || !dodInput.value) {
    hint.textContent = '';
    return;
  }
  var earliest = addDaysJS(dodInput.value, 9);
  hint.textContent = 'Earliest available funeral Mass date: ' + earliest + ' (9-day mourning period), fixed at 1:00 PM.';
  dateInput.min = earliest;
}

document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('serviceSelect').addEventListener('change', toggleServiceUI);
  document.getElementById('scheduleTypeRegular').addEventListener('change', toggleServiceUI);
  document.getElementById('scheduleTypeSpecial').addEventListener('change', toggleServiceUI);
  document.getElementById('priestSelect').addEventListener('change', refreshAvailability);
  document.getElementById('dateOfDeathInput').addEventListener('change', function () {
    updateEarliestFuneralHint();
    refreshAvailability();
  });
  document.getElementById('appointmentDateInput').addEventListener('change', function () {
    var select = document.getElementById('serviceSelect');
    var category = select.options[select.selectedIndex]?.dataset.category || '';
    if (category === 'Mass Intention') autoAssignMassTime();
    else updateOccupiedTimesHint();
  });
  document.getElementById('freeTimeInput').addEventListener('change', refreshAvailability);

  // Auto-compose the hidden guest_name from the split first/last/middle fields
  var guestLast = document.getElementById('guestLastNameInput');
  var guestFirst = document.getElementById('guestFirstNameInput');
  var guestMiddle = document.getElementById('guestMiddleNameInput');
  var guestCombined = document.getElementById('guestCombinedName');
  if (guestLast && guestFirst && guestCombined) {
    function syncGuestName() {
      var last = guestLast.value.trim();
      var first = guestFirst.value.trim();
      var mid = guestMiddle ? guestMiddle.value.trim() : '';
      guestCombined.value = last + ', ' + first + (mid ? ' ' + mid : '');
    }
    [guestLast, guestFirst, guestMiddle].filter(Boolean).forEach(function (el) {
      el.addEventListener('input', syncGuestName);
    });
    syncGuestName();
  }

  document.getElementById('massTimeSelect').addEventListener('change', function () {
    document.getElementById('massTimeInput').value = this.value;
    refreshAvailability();
  });

  document.getElementById('bookForm').addEventListener('invalid', function (e) {
    e.preventDefault();
    var errorBox = document.getElementById('bookFormError');
    var label = 'a required field';
    if (e.target.labels && e.target.labels.length > 0) {
      label = e.target.labels[0].textContent.replace(' (optional)', '').replace('*', '').trim();
    } else if (e.target.previousElementSibling && e.target.previousElementSibling.tagName === 'LABEL') {
      label = e.target.previousElementSibling.textContent.replace(' (optional)', '').replace('*', '').trim();
    } else if (e.target.name) {
      label = e.target.name.replace(/_/g, ' ');
    }
    
    errorBox.textContent = 'Please fill out ' + label + '.';
    errorBox.style.display = 'block';

    if (!this.dataset.isInvalidated) {
      this.dataset.isInvalidated = 'true';
      setTimeout(function() { document.getElementById('bookForm').dataset.isInvalidated = ''; }, 100);
      e.target.focus();
      e.target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, true);

  document.getElementById('bookForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var submitBtn = document.getElementById('bookSubmitBtn');
    var errorBox = document.getElementById('bookFormError');
    errorBox.style.display = 'none';

    // Compose guest full name before submission
    if (guestCombined && typeof syncGuestName === 'function') syncGuestName();

    // Guest phone validation
    var guestPhoneEl = document.getElementById('guestPhoneInput');
    if (guestPhoneEl && guestPhoneEl.value.trim()) {
      if (!/^09[0-9]{9}$/.test(guestPhoneEl.value.trim())) {
        errorBox.textContent = 'Phone number must be exactly 11 digits starting with 09 (e.g. 09XXXXXXXXX).';
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        guestPhoneEl.focus();
        return;
      }
    }

    // Enforce required document uploads
    var missingDocs = [];
    document.querySelectorAll('#requirementRows input[type="file"][required]').forEach(function (inp) {
      if (!inp.files || inp.files.length === 0) {
        missingDocs.push(inp.dataset.label || 'Required document');
      }
    });
    if (missingDocs.length > 0) {
      errorBox.textContent = 'Please upload all required documents before submitting: ' + missingDocs.join(', ') + '.';
      errorBox.style.display = 'block';
      errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      return;
    }

    var serviceSelect = document.getElementById('serviceSelect');
    var category = serviceSelect.options[serviceSelect.selectedIndex]?.dataset.category || '';

    var isMassIntention = (serviceSelect.options[serviceSelect.selectedIndex]?.dataset.category || '') === 'Mass Intention';
    if (isMassIntention) {
      var massDate = document.getElementById('appointmentDateInput').value;
      var massTime = document.getElementById('massTimeInput').value;
      if (!massDate || !massTime) {
        errorBox.textContent = !massDate
          ? 'Please choose the date of the Mass.'
          : 'Please choose an available Mass time.';
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById(!massDate ? 'appointmentDateInput' : 'massTimeSelect').focus();
        return;
      }
      var amount = parseFloat(document.getElementById('offeringAmount').value);
      if (!(amount > 0)) {
        errorBox.textContent = 'Offering amount must be greater than ₱0.';
        errorBox.style.display = 'block';
        errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById('offeringAmount').focus();
        return;
      }
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    var formData = new FormData(e.target);
    fetch('<?= url('parishioner/book.php') ?>', { method: 'POST', body: formData })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.success && data.redirect) {
          // PayMongo's hosted checkout (provider-required redirect) — the
          // Mass Intention only counts once that payment is actually completed.
          submitBtn.textContent = 'Redirecting to secure payment…';
          window.location.href = data.redirect;
          return;
        }
        submitBtn.disabled = false;
        submitBtn.textContent = submitButtonLabel();
        if (data.success) {
          if (data.redirect) {
            window.location.href = data.redirect;
            return;
          }
          document.getElementById('bookFormView').style.display = 'none';
          document.getElementById('bookConfirmView').style.display = 'block';
          document.getElementById('bookConfirmMessage').textContent = data.message + (data.documents_reminder ? ' ' + data.documents_reminder : '');
          var detailLink = document.getElementById('bookConfirmDetailLink');
          var referenceBox = document.getElementById('bookConfirmReferenceBox');
          if (data.guest_reference) {
            detailLink.style.display = 'none';
            document.getElementById('bookConfirmReferenceCode').textContent = data.guest_reference;
            referenceBox.style.display = 'block';
          } else {
            detailLink.style.display = '';
            detailLink.href = data.detail_url;
            referenceBox.style.display = 'none';
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
          errorBox.textContent = data.message;
          errorBox.style.display = 'block';
          errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      })
      .catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = submitButtonLabel();
        errorBox.textContent = 'Something went wrong submitting your request. Please try again.';
        errorBox.style.display = 'block';
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

