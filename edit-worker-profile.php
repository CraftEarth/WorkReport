<?php

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/worker-profile.php';

require_login();
ensure_worker_profile_schema();

$workerId = (int) (
    $_GET['worker_id']
    ?? $_POST['worker_id']
    ?? 0
);

if ($workerId > 0) {
    if (!worker_accessible($workerId)) {
        http_response_code(403);
        exit('Access denied.');
    }

    $_SESSION['worker_id'] = $workerId;
}

$worker = require_current_worker();
$workerId = (int) $worker['id'];
$pdo = db();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    
    verify_csrf(
        $_POST['_csrf'] ?? null
    );

$fullName = trim($_POST['full_name'] ?? '');
    $birthDate = trim($_POST['birth_date'] ?? '');
    $jobTitle = trim($_POST['job_title'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $shiftName = trim($_POST['shift_name'] ?? '');
    $hireDate = trim($_POST['hire_date'] ?? '');
    $primaryWorkArea =
        trim($_POST['primary_work_area'] ?? '');
    $supervisorName =
        trim($_POST['supervisor_name'] ?? '');
    $trainingStatus =
        trim($_POST['training_status'] ?? '');
    $employeeNumber =
        trim($_POST['employee_number'] ?? '');
    $employer = trim($_POST['employer'] ?? '');

    if ($fullName === '') {
        $error = 'Worker name is required.';
    }

    $newPhotoFile = null;

    if (
        $error === ''
        && isset($_FILES['profile_photo'])
        && is_array($_FILES['profile_photo'])
        && (int) ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_NO_FILE
    ) {

        $upload = $_FILES['profile_photo'];

        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $error = 'Profile photo upload failed.';
        } elseif ((int) $upload['size'] > 3 * 1024 * 1024) {
            $error = 'Profile photo must be 3 MB or smaller.';
        } else {

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($upload['tmp_name']);

            $allowed = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            if (!isset($allowed[$mime])) {
                $error =
                    'Profile photo must be JPG, PNG, or WEBP.';
            } else {

                $directory =
    STORAGE_PATH
    . DIRECTORY_SEPARATOR
    . 'profile-photos';
                if (
                    !is_dir($directory)
                    && !mkdir($directory, 0700, true)
                    && !is_dir($directory)
                ) {
                    $error =
                        'Unable to create profile photo storage.';
                } else {

                    $newPhotoFile =
                        bin2hex(random_bytes(16))
                        . '.'
                        . $allowed[$mime];

                    $destination =
                        $directory
                        . DIRECTORY_SEPARATOR
                        . $newPhotoFile;

                    if (
                        !move_uploaded_file(
                            $upload['tmp_name'],
                            $destination
                        )
                    ) {
                        $newPhotoFile = null;
                        $error =
                            'Unable to save profile photo.';
                    }
                }
            }
        }
    }

    if ($error === '') {

        $oldPhotoFile =
            trim(
                (string) (
                    $worker['profile_photo_file']
                    ?? ''
                )
            );

        $photoFile =
            $newPhotoFile
            ?? $oldPhotoFile;

        $stmt = $pdo->prepare("
            UPDATE workers
            SET
                full_name = :full_name,
                birth_date = :birth_date,
                job_title = :job_title,
                department = :department,
                shift_name = :shift_name,
                hire_date = :hire_date,
                primary_work_area = :primary_work_area,
                supervisor_name = :supervisor_name,
                training_status = :training_status,
                employee_number = :employee_number,
                employer = :employer,
                profile_photo_file = :profile_photo_file,
                profile_updated_at =
                    datetime('now', 'localtime')
            WHERE id = :id
        ");

        $stmt->execute([
            ':full_name' => $fullName,
            ':birth_date' =>
                $birthDate !== '' ? $birthDate : null,
            ':job_title' => $jobTitle,
            ':department' => $department,
            ':shift_name' => $shiftName,
            ':hire_date' =>
                $hireDate !== '' ? $hireDate : null,
            ':primary_work_area' => $primaryWorkArea,
            ':supervisor_name' => $supervisorName,
            ':training_status' => $trainingStatus,
            ':employee_number' => $employeeNumber,
            ':employer' => $employer,
            ':profile_photo_file' =>
                $photoFile !== '' ? $photoFile : null,
            ':id' => $workerId
        ]);

        /*
         * Preservation rule:
         * Replacing the active profile photo must not destroy the
         * previously stored file. Historical photo tracking will be
         * attached to these preserved files in a later migration.
         */
        if (
            $newPhotoFile !== null
            && $oldPhotoFile !== ''
            && $oldPhotoFile !== $newPhotoFile
        ) {
            // Intentionally preserved. Do not unlink the prior file.
        }

        flash(
            'success',
            'Worker profile updated.'
        );

        redirect(
            'worker-profile.php?worker_id='
            . $workerId
        );
    }
}

$stmt = $pdo->prepare("
    SELECT *
    FROM workers
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$workerId]);
$worker = $stmt->fetch();

$pageTitle = 'Edit Worker Profile';

require __DIR__ . '/includes/header.php';

?>

<section class="page-heading">

    <div>

        <h1>Edit Worker Profile</h1>

        <p>
            Stable job context used when reviewing workplace reports.
        </p>

    </div>

</section>


<?php if ($error): ?>

    <div class="flash flash-error">
        <?= e($error) ?>
    </div>

<?php endif; ?>


<form
    method="post"
    enctype="multipart/form-data"
>
    <?= csrf_field() ?>

    <input
        type="hidden"
        name="worker_id"
        value="<?= $workerId ?>"
    >


    <section class="panel compact-form">

        <h2>Identity</h2>

        <div class="form-grid">

            <div class="field">

                <label>Full Name *</label>

                <input
                    type="text"
                    name="full_name"
                    value="<?= e($worker['full_name']) ?>"
                    required
                >

            </div>

            <div class="field">

                <label>Date of Birth</label>

                <input
                    type="date"
                    name="birth_date"
                    value="<?= e($worker['birth_date'] ?? '') ?>"
                >

                <small class="help-text">
                    Used to calculate age. Optional.
                </small>

            </div>

            <div class="field">

                <label>Employee Number</label>

                <input
                    type="text"
                    name="employee_number"
                    value="<?= e($worker['employee_number'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label>Profile Photo</label>

                <input
                    type="file"
                    name="profile_photo"
                    accept="image/jpeg,image/png,image/webp"
                >

                <small class="help-text">
                    Optional. JPG, PNG or WEBP. Maximum 3 MB.
                </small>

            </div>

        </div>

    </section>


    <section class="panel compact-form">

        <h2>Job Context</h2>

        <div class="form-grid">

            <div class="field">

                <label>Job Title</label>

                <input
                    type="text"
                    name="job_title"
                    value="<?= e($worker['job_title'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label>Department</label>

                <input
                    type="text"
                    name="department"
                    value="<?= e($worker['department'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label>Shift</label>

                <input
                    type="text"
                    name="shift_name"
                    value="<?= e($worker['shift_name'] ?? '') ?>"
                    placeholder="1st, 2nd, 7 AM - 3 PM..."
                >

            </div>

            <div class="field">

                <label>Hire Date</label>

                <input
                    type="date"
                    name="hire_date"
                    value="<?= e($worker['hire_date'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label>Primary Work Area / Machine</label>

                <input
                    type="text"
                    name="primary_work_area"
                    value="<?= e($worker['primary_work_area'] ?? '') ?>"
                    placeholder="CD6, Ford Service, Quality..."
                >

            </div>

            <div class="field">

                <label>Supervisor / Lead</label>

                <input
                    type="text"
                    name="supervisor_name"
                    value="<?= e($worker['supervisor_name'] ?? '') ?>"
                >

            </div>

            <div class="field">

                <label>Training Status</label>

                <select name="training_status">

                    <?php
                    $trainingOptions = [
                        '' => 'Not specified',
                        'new_hire' => 'New Hire',
                        'in_training' => 'In Training',
                        'qualified' => 'Qualified / Released',
                        'cross_training' => 'Cross-Training',
                        'other' => 'Other'
                    ];
                    ?>

                    <?php foreach (
                        $trainingOptions
                        as $value => $label
                    ): ?>

                        <option
                            value="<?= e($value) ?>"
                            <?= ($worker['training_status'] ?? '') === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="field">

                <label>Employer</label>

                <input
                    type="text"
                    name="employer"
                    value="<?= e($worker['employer'] ?? '') ?>"
                >

            </div>

        </div>

    </section>


    <div class="form-actions">

        <a
            href="worker-profile.php?worker_id=<?= $workerId ?>"
            class="button button-secondary"
        >
            Cancel
        </a>

        <button
            type="submit"
            class="button button-primary"
        >
            Save Profile
        </button>

    </div>

</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
