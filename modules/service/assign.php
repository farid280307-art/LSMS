<?php
// This file is a component included by view.php
// All necessary variables like $service, $technicians are provided by view.php
?>

<form method="POST" action="" onsubmit="return confirmChangeTechnician()">
    <input type="hidden" name="action" value="assign_tech">
    <div class="mb-3">
        <label class="form-label">Assign Teknisi</label>
        <select name="technician_id" class="form-select" required>
            <option value="">-- Pilih Teknisi --</option>
            <?php foreach ($technicians as $teknisi): ?>
                <option value="<?= $teknisi['id'] ?>" <?= ($service['technician_id'] == $teknisi['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($teknisi['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary w-100">Tugaskan / Update</button>
</form>

<script>
function confirmChangeTechnician() {
    const currentTechId = '<?= $service['technician_id'] ?? '' ?>';
    const currentStatus = '<?= $service['status'] ?? '' ?>';
    const newTechId = document.querySelector('select[name="technician_id"]').value;
    const activeStatuses = ['diagnosis', 'on_progress', 'waiting_sparepart'];

    // Cek jika teknisi sudah ada, teknisi baru dipilih, dan statusnya aktif
    if (currentTechId && newTechId && currentTechId !== newTechId && activeStatuses.includes(currentStatus)) {
        return confirm('Teknisi sudah ditugaskan untuk service ini. Apakah Anda yakin ingin mengganti teknisi?');
    }

    // Jika tidak perlu konfirmasi, izinkan submit
    return true;
}
</script>