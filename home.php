<?php

// ==========================================================
// HOME.PHP - halaman peserta
// Menu dipilih lewat alamat: home.php?menu=kelas | pendaftaran | profil
// ==========================================================

session_start();
require_once 'koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ----------------------------------------------------------
// FUNGSI BANTU
// ----------------------------------------------------------
function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function setFlash($type, $text) {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function rupiah($n) {
    return 'Rp ' . number_format((int) $n, 0, ',', '.');
}

// Ambil isi form sebagai teks (menolak array yang dikirim paksa)
function post($kunci) {
    return (isset($_POST[$kunci]) && is_string($_POST[$kunci])) ? $_POST[$kunci] : '';
}

// ----------------------------------------------------------
// AKSES: HARUS LOGIN
// ----------------------------------------------------------
if (!isset($_SESSION['email'])) {
    header('Location: index.php');
    exit();
}

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}

// Data pengguna dibaca dari database di setiap akses (butuh kolom users.role)
$stmt = $conn->prepare("SELECT id_user, name, email, role FROM users WHERE email = ?");
$stmt->bind_param("s", $_SESSION['email']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}
$id_user = (int) $user['id_user'];

// Token CSRF untuk semua form POST
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

function cekCsrf($tujuan) {
    if (!hash_equals($GLOBALS['csrf'], post('csrf'))) {
        setFlash('error', 'Sesi tidak valid, muat ulang halaman lalu coba lagi.');
        header('Location: home.php?menu=' . $tujuan);
        exit();
    }
}

// Menu yang sedang dibuka
$menu = $_GET['menu'] ?? 'kelas';
if (!in_array($menu, ['kelas', 'pendaftaran', 'profil'], true)) {
    $menu = 'kelas';
}

// ==========================================================
// PROSES FORM (daftar, batal, ubah nama, ganti password)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = post('aksi');
    $peta = ['daftar_banyak' => 'kelas', 'batal' => 'pendaftaran', 'ubah_nama' => 'profil', 'ganti_password' => 'profil'];
    $tujuan = $peta[$aksi] ?? 'kelas';

    cekCsrf($tujuan);

    try {

        // ---------- Daftar beberapa kelas sekaligus ----------
        if ($aksi === 'daftar_banyak') {
            $conn->begin_transaction();

            // Id jadwal yang dipilih: dibersihkan dan tanpa duplikat
            $dikirim = array_filter((array) ($_POST['jadwal_ids'] ?? []), 'is_scalar');
            $ids = array_values(array_unique(array_filter(array_map('intval', $dikirim), function ($i) {
                return $i > 0;
            })));

            if (count($ids) === 0) {
                throw new RuntimeException('Pilih minimal satu kelas.');
            }
            if (count($ids) > 10) {
                throw new RuntimeException('Maksimal 10 kelas sekali daftar.');
            }

            // Semua kelas diproses dalam satu transaksi: gagal satu, batal semua
            $total = 0;
            foreach ($ids as $id) {
                // Harga dihitung dari database, bukan dari data yang dikirim browser
                $stmt = $conn->prepare("SELECT k.nama_kelas, k.harga FROM jadwal j JOIN kelas k ON k.id_kelas = j.id_kelas WHERE j.id_jadwal = ? AND j.tanggal >= CURDATE()");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $info = $stmt->get_result()->fetch_assoc();

                if (!$info) {
                    throw new RuntimeException('Salah satu jadwal tidak tersedia lagi. Tidak ada pendaftaran yang diproses.');
                }

                // 1. Catat pendaftaran (UNIQUE id_user + id_jadwal mencegah daftar dua kali)
                $stmt = $conn->prepare("INSERT INTO pendaftaran (id_user, id_jadwal) VALUES (?, ?)");
                $stmt->bind_param("ii", $id_user, $id);
                $stmt->execute();

                // 2. Kurangi kuota, hanya jika masih ada sisa
                $stmt = $conn->prepare("UPDATE jadwal SET kuota = kuota - 1 WHERE id_jadwal = ? AND kuota > 0");
                $stmt->bind_param("i", $id);
                $stmt->execute();

                if ($stmt->affected_rows === 0) {
                    throw new RuntimeException('Slot kelas "' . $info['nama_kelas'] . '" sudah penuh. Tidak ada pendaftaran yang diproses.');
                }

                $total += (int) $info['harga'];
            }

            $conn->commit();
            setFlash('success', 'Pendaftaran berhasil untuk ' . count($ids) . ' kelas. Total yang harus dibayar: ' . rupiah($total) . '.');

        // ---------- Batalkan pendaftaran (masih menunggu pembayaran dan jadwal belum lewat) ----------
        } elseif ($aksi === 'batal') {
            $idPendaftaran = (int) post('id_pendaftaran');
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT p.id_jadwal
                FROM pendaftaran p
                JOIN jadwal j ON j.id_jadwal = p.id_jadwal
                WHERE p.id_pendaftaran = ? AND p.id_user = ? AND p.status = 'menunggu' AND j.tanggal >= CURDATE()
            ");
            $stmt->bind_param("ii", $idPendaftaran, $id_user);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if (!$row) {
                throw new RuntimeException('Pendaftaran tidak ditemukan atau sudah tidak bisa dibatalkan.');
            }
            $idJadwal = (int) $row['id_jadwal'];

            // 1. Hapus pendaftaran milik user ini
            $stmt = $conn->prepare("DELETE FROM pendaftaran WHERE id_pendaftaran = ? AND id_user = ?");
            $stmt->bind_param("ii", $idPendaftaran, $id_user);
            $stmt->execute();

            // 2. Kembalikan slot
            $stmt = $conn->prepare("UPDATE jadwal SET kuota = kuota + 1 WHERE id_jadwal = ?");
            $stmt->bind_param("i", $idJadwal);
            $stmt->execute();

            $conn->commit();
            setFlash('success', 'Pendaftaran berhasil dibatalkan.');

        // ---------- Ubah nama ----------
        } elseif ($aksi === 'ubah_nama') {
            $nama = trim(post('name'));
            if (mb_strlen($nama) < 2 || mb_strlen($nama) > 100) {
                throw new RuntimeException('Nama harus 2 sampai 100 karakter.');
            }

            $stmt = $conn->prepare("UPDATE users SET name = ? WHERE id_user = ?");
            $stmt->bind_param("si", $nama, $id_user);
            $stmt->execute();

            $_SESSION['name'] = $nama;
            setFlash('success', 'Nama berhasil diperbarui.');

        // ---------- Ganti password ----------
        } elseif ($aksi === 'ganti_password') {
            $lama = post('password_lama');
            $baru = post('password_baru');
            $konfirmasi = post('konfirmasi');

            $stmt = $conn->prepare("SELECT password FROM users WHERE id_user = ?");
            $stmt->bind_param("i", $id_user);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if (!$row || !password_verify($lama, $row['password'])) {
                throw new RuntimeException('Password lama salah.');
            }
            if (strlen($baru) < 8) {
                throw new RuntimeException('Password baru minimal 8 karakter.');
            }
            if ($baru !== $konfirmasi) {
                throw new RuntimeException('Konfirmasi password tidak sama.');
            }
            if ($baru === $lama) {
                throw new RuntimeException('Password baru harus berbeda dari password lama.');
            }

            $hash = password_hash($baru, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id_user = ?");
            $stmt->bind_param("si", $hash, $id_user);
            $stmt->execute();

            session_regenerate_id(true);
            setFlash('success', 'Password berhasil diganti.');

        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062 && $aksi === 'daftar_banyak') {
            setFlash('error', 'Kamu sudah terdaftar di salah satu jadwal yang dipilih. Tidak ada pendaftaran yang diproses.');
        } elseif ($e->getCode() === 1451 && $aksi === 'batal') {
            // Pendaftaran sudah punya data pembayaran
            setFlash('error', 'Pendaftaran tidak bisa dibatalkan karena sudah ada pembayaran.');
        } else {
            setFlash('error', 'Terjadi kesalahan, coba lagi.');
        }
    } catch (RuntimeException $e) {
        // Harus setelah mysqli_sql_exception (yang merupakan turunan RuntimeException)
        $conn->rollback();
        setFlash('error', $e->getMessage());
    }

    header('Location: home.php?menu=' . $tujuan);
    exit();
}

// ==========================================================
// DATA UNTUK TAMPILAN
// ==========================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$inisial = strtoupper(mb_substr($user['name'], 0, 1));
$adalahAdmin = $user['role'] === 'admin';
// Klik nama di sidebar: user ke profil, admin ke halaman admin
$linkProfil = $adalahAdmin ? 'admin_page.php' : 'home.php?menu=profil';

$judulMenu = ['kelas' => 'Kelas', 'pendaftaran' => 'Pendaftaran Saya', 'profil' => 'Profil'];

if ($menu === 'kelas') {
    // Jadwal yang sudah didaftar user ini
    $terdaftar = [];
    $stmt = $conn->prepare("SELECT id_jadwal FROM pendaftaran WHERE id_user = ?");
    $stmt->bind_param("i", $id_user);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $terdaftar[] = (int) $row['id_jadwal'];
    }

    // Daftar jadwal yang akan datang
    $jadwal = $conn->query("
        SELECT j.id_jadwal, j.tanggal, j.jam_mulai, j.jam_selesai, j.lokasi, j.kuota,
               k.nama_kelas, k.deskripsi, k.harga, k.durasi_jam, k.gambar,
               i.nama AS nama_instruktur
        FROM jadwal j
        JOIN kelas k ON k.id_kelas = j.id_kelas
        JOIN instruktur i ON i.id_instruktur = j.id_instruktur
        WHERE j.tanggal >= CURDATE()
        ORDER BY j.tanggal, j.jam_mulai
    ");

    // Banner: file img/banner.{jpg|png|webp}, bisa diganti dari halaman admin
    $banner = glob(__DIR__ . '/img/banner.*')[0] ?? null;

} elseif ($menu === 'pendaftaran') {
    // Semua pendaftaran milik user ini
    $stmt = $conn->prepare("
        SELECT p.id_pendaftaran, p.status,
               j.tanggal, j.jam_mulai, j.jam_selesai, j.lokasi, (j.tanggal >= CURDATE()) AS akan_datang,
               k.nama_kelas, k.harga
        FROM pendaftaran p
        JOIN jadwal j ON j.id_jadwal = p.id_jadwal
        JOIN kelas k ON k.id_kelas = j.id_kelas
        WHERE p.id_user = ?
        ORDER BY j.tanggal, j.jam_mulai
    ");
    $stmt->bind_param("i", $id_user);
    $stmt->execute();
    $daftar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Total yang belum dibayar = pendaftaran berstatus 'menunggu'
    $totalBelumBayar = 0;
    $jumlahBelumBayar = 0;
    foreach ($daftar as $d) {
        if ($d['status'] === 'menunggu') {
            $totalBelumBayar += (int) $d['harga'];
            $jumlahBelumBayar++;
        }
    }

    $labelStatus = [
        'menunggu' => ['Menunggu pembayaran', 'badge-user'],
        'dikonfirmasi' => ['Terkonfirmasi', 'badge-success'],
        'dibatalkan' => ['Dibatalkan', 'badge-user'],
    ];
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($judulMenu[$menu]); ?> - Cooking Class</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="ada-sidebar">

<div class="app-layout">

    <!-- ===== SIDEBAR ===== -->
    <aside class="sidebar">
        <a href="<?= $linkProfil; ?>" class="sidebar-profile <?= (!$adalahAdmin && $menu === 'profil') ? 'aktif' : ''; ?>">
            <div class="avatar"><?= h($inisial); ?></div>
            <div>
                <div class="nama"><?= h($user['name']); ?></div>
                <div class="peran"><?= $adalahAdmin ? 'Admin' : 'Peserta'; ?></div>
            </div>
        </a>

        <nav>
            <a href="home.php?menu=kelas" class="<?= $menu === 'kelas' ? 'active' : ''; ?>">Kelas</a>
            <a href="home.php?menu=pendaftaran" class="<?= $menu === 'pendaftaran' ? 'active' : ''; ?>">Pendaftaran Saya</a>
        </nav>

        <div class="sidebar-bottom">
            <nav>
                <a href="home.php?logout=1">Logout</a>
            </nav>
        </div>
    </aside>

    <div class="app-body">

        <?php if ($menu === 'kelas' && !empty($banner)): ?>
            <img class="banner" src="img/<?= h(basename($banner)); ?>?v=<?= filemtime($banner); ?>" alt="Cooking Class">
        <?php endif; ?>

        <main class="app-main">

            <?php if ($flash): ?>
                <div class="message <?= h($flash['type']); ?>"><?= h($flash['text']); ?></div>
            <?php endif; ?>


            <?php /* ================= MENU: KELAS ================= */ ?>
            <?php if ($menu === 'kelas'): ?>

                <div class="page-header">
                    <div>
                        <h1>Cooking Class</h1>
                        <p>Pilih jadwal kelas yang ingin kamu ikuti.</p>
                    </div>
                </div>

                <?php if ($jadwal->num_rows === 0): ?>
                    <p class="empty">Belum ada jadwal kelas yang tersedia.</p>
                <?php endif; ?>

                <div class="grid">
                    <?php while ($j = $jadwal->fetch_assoc()): ?>
                        <?php
                            $sudahDaftar = in_array((int) $j['id_jadwal'], $terdaftar);
                            $penuh = $j['kuota'] <= 0;
                            $tanggal = date('d-m-Y', strtotime($j['tanggal']));
                            $jam = substr($j['jam_mulai'], 0, 5) . ' - ' . substr($j['jam_selesai'], 0, 5);
                        ?>
                        <div class="card">

                            <div class="card-body">
                                <div class="card-title">
                                    <h2><?= h($j['nama_kelas']); ?></h2>
                                </div>

                                <p class="desc"><?= h($j['deskripsi']); ?></p>
                                <div class="baca-wrap">
                                    <button type="button" class="baca-lagi" hidden>Baca selengkapnya</button>
                                </div>

                                <div class="info">
                                    <span>Tanggal</span>
                                    <span><?= $tanggal; ?></span>
                                </div>
                                <div class="info">
                                    <span>Jam</span>
                                    <span><?= $jam; ?> (<?= (int) $j['durasi_jam']; ?> jam)</span>
                                </div>
                                <div class="info">
                                    <span>Lokasi</span>
                                    <span><?= h($j['lokasi'] ?? '-'); ?></span>
                                </div>
                                <div class="info">
                                    <span>Instruktur</span>
                                    <span><?= h($j['nama_instruktur']); ?></span>
                                </div>
                                <div class="info">
                                    <span>Harga</span>
                                    <span class="price"><?= rupiah($j['harga']); ?></span>
                                </div>
                                <div class="info">
                                    <span>Slot tersedia</span>
                                    <?php if ($penuh): ?>
                                        <span class="slot-habis">Penuh</span>
                                    <?php else: ?>
                                        <span><?= (int) $j['kuota']; ?> peserta</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($sudahDaftar): ?>
                                    <button type="button" class="btn" disabled>Sudah Daftar</button>

                                <?php elseif ($penuh): ?>
                                    <button type="button" class="btn" disabled>Slot Penuh</button>

                                <?php else: ?>
                                    <label class="pilih">
                                        <input type="checkbox" class="pilih-kelas" autocomplete="off"
                                            data-id="<?= (int) $j['id_jadwal']; ?>"
                                            data-nama="<?= h($j['nama_kelas']); ?>"
                                            data-harga="<?= (int) $j['harga']; ?>"
                                            data-waktu="<?= h($tanggal . ', ' . $jam); ?>">
                                        <span>Pilih kelas ini</span>
                                    </label>
                                <?php endif; ?>
                            </div>

                            <!-- Foto dikunci rasio 4:5, kelebihannya terpotong (object-fit: cover) -->
                            <div class="card-image">
                                <?php if (!empty($j['gambar'])): ?>
                                    <img src="img/<?= h($j['gambar']); ?>" alt="<?= h($j['nama_kelas']); ?>"
                                         onerror="this.style.display='none'">
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endwhile; ?>
                </div>

                <div class="spacer-bawah"></div>


            <?php /* ================= MENU: PENDAFTARAN SAYA ================= */ ?>
            <?php elseif ($menu === 'pendaftaran'): ?>

                <div class="page-header">
                    <div>
                        <h1>Pendaftaran Saya</h1>
                        <p>Kelas yang sudah kamu daftar dan total yang perlu dibayar.</p>
                    </div>
                </div>

                <?php if (count($daftar) === 0): ?>
                    <p class="empty">Kamu belum mendaftar kelas apa pun. <a href="home.php?menu=kelas" style="color: var(--gold-light);">Lihat daftar kelas</a></p>
                <?php else: ?>

                    <div class="panel">
                        <div class="table-wrap">
                            <table class="data">
                                <tr><th>Kelas</th><th>Jadwal</th><th>Lokasi</th><th>Harga</th><th>Status</th><th></th></tr>
                                <?php foreach ($daftar as $d): ?>
                                    <?php
                                        $tanggal = date('d-m-Y', strtotime($d['tanggal']));
                                        $jam = substr($d['jam_mulai'], 0, 5) . ' - ' . substr($d['jam_selesai'], 0, 5);
                                        [$teksStatus, $kelasStatus] = $labelStatus[$d['status']] ?? [$d['status'], 'badge-user'];
                                        $bisaBatal = $d['status'] === 'menunggu' && (int) $d['akan_datang'] === 1;
                                    ?>
                                    <tr>
                                        <td><?= h($d['nama_kelas']); ?></td>
                                        <td><?= $tanggal; ?><br><span class="muted"><?= $jam; ?></span></td>
                                        <td><?= h($d['lokasi'] ?? '-'); ?></td>
                                        <td class="price"><?= rupiah($d['harga']); ?></td>
                                        <td><span class="badge <?= $kelasStatus; ?>"><?= h($teksStatus); ?></span></td>
                                        <td>
                                            <?php if ($bisaBatal): ?>
                                                <form method="POST" action="home.php?menu=pendaftaran">
                                                    <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                                                    <input type="hidden" name="aksi" value="batal">
                                                    <input type="hidden" name="id_pendaftaran" value="<?= (int) $d['id_pendaftaran']; ?>">
                                                    <button type="button" class="btn btn-small btn-danger"
                                                        data-confirm-title="Batalkan pendaftaran?"
                                                        data-confirm-detail="<?= h($d['nama_kelas'] . ' - ' . $tanggal . ', ' . $jam); ?>"
                                                        data-confirm-note="Slot kamu akan dikembalikan dan bisa diambil peserta lain."
                                                        data-confirm-yes="Ya, Batalkan"
                                                        data-confirm-no="Kembali"
                                                        data-confirm-danger="1">Batalkan</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="info">
                            <span>Kelas yang belum dibayar</span>
                            <span><?= $jumlahBelumBayar; ?> kelas</span>
                        </div>
                        <div class="info">
                            <span>Total yang harus dibayar</span>
                            <span class="price"><?= rupiah($totalBelumBayar); ?></span>
                        </div>
                    </div>

                <?php endif; ?>


            <?php /* ================= MENU: PROFIL ================= */ ?>
            <?php else: ?>

                <div class="page-header">
                    <div>
                        <h1>Profil</h1>
                        <p>Kelola data akunmu.</p>
                    </div>
                </div>

                <div class="panel">
                    <h2>Data Akun</h2>
                    <form method="POST" action="home.php?menu=profil">
                        <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                        <input type="hidden" name="aksi" value="ubah_nama">

                        <div class="form-grid">
                            <div class="field">
                                <label>Nama</label>
                                <input type="text" name="name" value="<?= h($user['name']); ?>" maxlength="100" required>
                            </div>
                            <div class="field">
                                <label>Email</label>
                                <input type="email" value="<?= h($user['email']); ?>" disabled>
                                <div class="hint">Email dipakai untuk login dan tidak bisa diubah.</div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn">Simpan Nama</button>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <h2>Ganti Password</h2>
                    <form method="POST" action="home.php?menu=profil">
                        <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                        <input type="hidden" name="aksi" value="ganti_password">

                        <div class="form-grid">
                            <div class="field full">
                                <label>Password lama</label>
                                <input type="password" name="password_lama" autocomplete="current-password" required>
                            </div>
                            <div class="field">
                                <label>Password baru</label>
                                <input type="password" name="password_baru" minlength="8" autocomplete="new-password" required>
                                <div class="hint">Minimal 8 karakter.</div>
                            </div>
                            <div class="field">
                                <label>Ulangi password baru</label>
                                <input type="password" name="konfirmasi" minlength="8" autocomplete="new-password" required>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn">Ganti Password</button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </main>
    </div>
</div>

<?php if ($menu === 'kelas'): ?>
    <!-- Ringkasan kelas terpilih + total bayar (muncul saat ada kelas yang dipilih) -->
    <form method="POST" action="home.php?menu=kelas" id="form-daftar">
        <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
        <input type="hidden" name="aksi" value="daftar_banyak">
        <div id="ids-terpilih"></div>
        <div class="ringkasan" id="ringkasan" hidden>
            <div class="ringkasan-info">
                <strong id="ringkasan-jumlah">0 kelas dipilih</strong>
                <span>Total bayar: <b id="ringkasan-total">Rp 0</b></span>
            </div>
            <button type="button" class="btn btn-small" id="ringkasan-daftar">Daftar Sekarang</button>
        </div>
    </form>
<?php endif; ?>

<!-- Pop up (konfirmasi dan deskripsi lengkap), dipakai bersama oleh semua menu -->
<div class="modal" id="confirm-modal">
    <div class="modal-box">
        <h3 id="modal-title"></h3>
        <p id="modal-detail"></p>
        <ul class="modal-list" id="modal-list"></ul>
        <p id="modal-note"></p>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" id="modal-no">Batal</button>
            <button type="button" class="btn" id="modal-yes">Ya</button>
        </div>
    </div>
</div>

<script src="script.js"></script>
</body>
</html>
