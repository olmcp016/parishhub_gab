<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Parishioner');

$userId = currentUser()['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $firstname = trim($_POST['firstname'] ?? '');
    $lastname = trim($_POST['lastname'] ?? '');
    $middlename = trim($_POST['middlename'] ?? '') ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;
    $birthdate = $_POST['birthdate'] ?: null;
    $gender = $_POST['gender'] ?: null;

    if ($lastname === '' || $firstname === '') {
        flash('error', 'Please enter your last name and first name.');
        redirect(url('parishioner/profile.php'));
    }

    $stmt = db()->prepare(
        "UPDATE users SET firstname=?, lastname=?, middlename=?, phone=?, address=?, birthdate=?, gender=? WHERE user_id=?"
    );
    $stmt->execute([$firstname, $lastname, $middlename, $phone, $address, $birthdate, $gender, $userId]);

    $_SESSION['user']['firstname'] = $firstname;
    $_SESSION['user']['lastname'] = $lastname;

    flash('success', 'Profile updated successfully.');
    redirect(url('parishioner/profile.php'));
}

$stmt = db()->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

$active = 'profile';
$pageTitle = 'My Profile';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';
?>

<div class="card" style="max-width: 600px;">
  <div class="card-header"><h3>Edit Profile</h3></div>
  <form method="POST" action="<?= url('parishioner/profile.php') ?>" id="profileForm" novalidate>
    <?= csrfField() ?>
    <div class="form-row">
      <div class="form-group">
        <label>Last Name</label>
        <input type="text" name="lastname" value="<?= e($user['lastname']) ?>" required>
      </div>
      <div class="form-group">
        <label>First Name</label>
        <input type="text" name="firstname" value="<?= e($user['firstname']) ?>" required>
      </div>
      <div class="form-group">
        <label>Middle Name <span class="text-muted" style="font-weight:400;">(optional)</span></label>
        <input type="text" name="middlename" value="<?= e($user['middlename'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Email (cannot be changed)</label>
      <input type="email" value="<?= e($user['email']) ?>" disabled>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Phone</label>
        <input type="tel" name="phone" value="<?= e($user['phone']) ?>">
      </div>
      <div class="form-group">
        <label>Birthdate</label>
        <input type="date" name="birthdate" value="<?= e($user['birthdate']) ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Gender</label>
      <select name="gender">
        <option value="">Select</option>
        <option <?= $user['gender']==='Male'?'selected':'' ?>>Male</option>
        <option <?= $user['gender']==='Female'?'selected':'' ?>>Female</option>
        <option <?= $user['gender']==='Other'?'selected':'' ?>>Other</option>
      </select>
    </div>
    <div class="form-group">
      <label>Address</label>
      <input type="text" name="address" value="<?= e($user['address']) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Save Changes</button>
  </form>
</div>

<script src="<?= url('public/js/validation.js') ?>?v=<?= (int) @filemtime(__DIR__ . '/../public/js/validation.js') ?>"></script>
<script>
(function () {
  var form = document.getElementById('profileForm');
  var required = [
    ['[name="lastname"]', 'your last name'],
    ['[name="firstname"]', 'your first name'],
  ];
  clearFieldErrorOnInput(form, required.map(function (f) { return f[0]; }));
  form.addEventListener('submit', function (e) {
    var firstInvalid = validateRequiredFields(form, required);
    if (firstInvalid) {
      e.preventDefault();
      firstInvalid.focus();
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/dash-end.php'; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
