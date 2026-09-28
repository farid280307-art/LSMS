# TODO: Implement Bootstrap Logout Confirmation Modal

## Task: Create logout confirmation dialog using Bootstrap 5 Modal

### Steps:
1. [x] Add Bootstrap Modal HTML to sidebar.php (reusable logout confirmation modal)
2. [x] Update desktop logout button to use modal instead of confirm()
3. [x] Update mobile logout button to use modal

### Files Edited:
- views/layouts/sidebar.php

### Implementation Details:
- Created a modal with id="logoutConfirmModal"
- Modal content: Title "Konfirmasi Logout", Message "Apakah Anda yakin ingin keluar dari sistem?", Confirm button (red/danger), Cancel button
- Used data-bs-toggle="modal" and data-bs-target="#logoutConfirmModal" on logout links
- Both desktop and mobile logout buttons now trigger the Bootstrap modal

