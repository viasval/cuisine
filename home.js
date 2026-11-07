// Pop up konfirmasi untuk tombol yang punya atribut data-confirm-title
// Dipakai oleh home.php (Daftar dan Batalkan Pendaftaran)

const modal = document.getElementById('confirm-modal');
const modalTitle = document.getElementById('modal-title');
const modalDetail = document.getElementById('modal-detail');
const modalNote = document.getElementById('modal-note');
const modalYes = document.getElementById('modal-yes');
const modalNo = document.getElementById('modal-no');

let pendingForm = null;

function openModal(button) {
    pendingForm = button.closest('form');

    modalTitle.textContent = button.dataset.confirmTitle;
    modalDetail.textContent = button.dataset.confirmDetail || '';
    modalNote.textContent = button.dataset.confirmNote || '';
    modalYes.textContent = button.dataset.confirmYes || 'Ya';
    modalNo.textContent = button.dataset.confirmNo || 'Batal';

    modalYes.classList.toggle('btn-danger', button.dataset.confirmDanger === '1');
    modalYes.disabled = false;

    modal.classList.add('open');
    modalNo.focus();
}

function closeModal() {
    modal.classList.remove('open');
    pendingForm = null;
}

document.querySelectorAll('[data-confirm-title]').forEach(function (button) {
    button.addEventListener('click', function () {
        openModal(button);
    });
});

modalYes.addEventListener('click', function () {
    if (!pendingForm) return;
    modalYes.disabled = true; // cegah klik ganda
    pendingForm.submit();
});

modalNo.addEventListener('click', closeModal);

modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
});
