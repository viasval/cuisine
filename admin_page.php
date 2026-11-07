<?php

session_start();
require_once 'koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function setFlash($type, $text) {
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

// Simpan gambar kelas ke folder img/ sebagai kelas-{id}.{jpg|png|webp}
// Mengembalikan nama file, atau null kalau tidak ada file yang diunggah
// Dipakai untuk gambar kelas (awalan 'kelas-' + id) dan banner (awalan 'banner', id kosong)
function simpanGambar($file, $id, $awalan = 'kelas-', $maksMB = 2) {
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gambar gagal.');
    }
    if ($file['size'] > $maksMB * 1024 * 1024) {
        throw new RuntimeException('Ukuran gambar maksimal ' . $maksMB . ' MB.');
    }

    $info = @getimagesize($file['tmp_name']);
    $boleh = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($boleh[$info[2]])) {
        throw new RuntimeException('Format gambar harus JPG, PNG, atau WebP.');
    }

    $dir = __DIR__ . '/img/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Hapus versi lama (ekstensi bisa berbeda)
    foreach ($boleh as $ext) {
        @unlink($dir . $awalan . $id . '.' . $ext);
    }

    $nama = $awalan . $id . '.' . $boleh[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . $nama)) {
        throw new RuntimeException('Gambar tidak bisa disimpan ke folder img/.');
    }
    return $nama;
}

// ---------- Akses: harus login dan role admin ----------
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

// Role dibaca ulang dari database di setiap request, bukan dari session
$stmt = $conn->prepare("SELECT id_user, name, role FROM users WHERE email = ?");
$stmt->bind_param("s", $_SESSION['email']);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit();
}

if ($admin['role'] !== 'admin') {
    header('Location: home.php');
    exit();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$daftarHalaman = ['kelas' => 'Kelas', 'jadwal' => 'Jadwal', 'instruktur' => 'Instruktur', 'pengguna' => 'Pengguna', 'banner' => 'Banner'];
$page = $_GET['page'] ?? 'kelas';
if (!isset($daftarHalaman[$page])) {
    $page = 'kelas';
}

// ---------- Proses form ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $page = $_POST['page'] ?? 'kelas';
    if (!isset($daftarHalaman[$page])) {
        $page = 'kelas';
    }

    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        setFlash('error', 'Sesi tidak valid, muat ulang halaman lalu coba lagi.');
        header('Location: admin_page.php?page=' . $page);
        exit();
    }

    $aksi = $_POST['aksi'] ?? '';
    $hapusFile = null; // file gambar kelas yang dihapus setelah commit

    try {
        $conn->begin_transaction();

        if ($aksi === 'simpan_kelas') {
            $id = (int) ($_POST['id_kelas'] ?? 0);
            $nama = trim($_POST['nama_kelas'] ?? '');
            $deskripsi = trim($_POST['deskripsi'] ?? '');
            $harga = filter_var($_POST['harga'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $durasi = filter_var($_POST['durasi_jam'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($nama === '' || $harga === false || $durasi === false) {
                throw new RuntimeException('Nama, harga, dan durasi wajib diisi dengan benar.');
            }

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE kelas SET nama_kelas = ?, deskripsi = ?, harga = ?, durasi_jam = ? WHERE id_kelas = ?");
                $stmt->bind_param("ssiii", $nama, $deskripsi, $harga, $durasi, $id);
                $stmt->execute();
                $pesan = 'Kelas berhasil diperbarui.';
            } else {
                $stmt = $conn->prepare("INSERT INTO kelas (nama_kelas, deskripsi, harga, durasi_jam) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssii", $nama, $deskripsi, $harga, $durasi);
                $stmt->execute();
                $id = $conn->insert_id;
                $pesan = 'Kelas berhasil ditambahkan. Tambahkan jadwal agar muncul di halaman peserta.';
            }

            $gambar = simpanGambar($_FILES['gambar'] ?? null, $id);
            if ($gambar) {
                $stmt = $conn->prepare("UPDATE kelas SET gambar = ? WHERE id_kelas = ?");
                $stmt->bind_param("si", $gambar, $id);
                $stmt->execute();
            }
            setFlash('success', $pesan);

        } elseif ($aksi === 'simpan_jadwal') {
            $id = (int) ($_POST['id_jadwal'] ?? 0);
            $idKelas = (int) ($_POST['id_kelas'] ?? 0);
            $idInstruktur = (int) ($_POST['id_instruktur'] ?? 0);
            $tanggal = $_POST['tanggal'] ?? '';
            $mulai = $_POST['jam_mulai'] ?? '';
            $selesai = $_POST['jam_selesai'] ?? '';
            $lokasi = trim($_POST['lokasi'] ?? '');
            $kuota = filter_var($_POST['kuota'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            $tgl = DateTime::createFromFormat('Y-m-d', $tanggal);
            if ($idKelas <= 0 || $idInstruktur <= 0 || !$tgl || $tgl->format('Y-m-d') !== $tanggal || $mulai === '' || $selesai === '' || $kuota === false) {
                throw new RuntimeException('Semua kolom jadwal wajib diisi dengan benar.');
            }
            if ($selesai <= $mulai) {
                throw new RuntimeException('Jam selesai harus setelah jam mulai.');
            }
            $lokasi = $lokasi === '' ? null : $lokasi;

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE jadwal SET id_kelas = ?, id_instruktur = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?, lokasi = ?, kuota = ? WHERE id_jadwal = ?");
                $stmt->bind_param("iissssii", $idKelas, $idInstruktur, $tanggal, $mulai, $selesai, $lokasi, $kuota, $id);
                $stmt->execute();
                setFlash('success', 'Jadwal berhasil diperbarui.');
            } else {
                $stmt = $conn->prepare("INSERT INTO jadwal (id_kelas, id_instruktur, tanggal, jam_mulai, jam_selesai, lokasi, kuota) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissssi", $idKelas, $idInstruktur, $tanggal, $mulai, $selesai, $lokasi, $kuota);
                $stmt->execute();
                setFlash('success', 'Jadwal berhasil ditambahkan.');
            }

        } elseif ($aksi === 'simpan_instruktur') {
            $id = (int) ($_POST['id_instruktur'] ?? 0);
            $nama = trim($_POST['nama'] ?? '');
            $noHp = trim($_POST['no_hp'] ?? '');
            $keahlian = trim($_POST['keahlian'] ?? '');

            if ($nama === '') {
                throw new RuntimeException('Nama instruktur wajib diisi.');
            }
            $noHp = $noHp === '' ? null : $noHp;
            $keahlian = $keahlian === '' ? null : $keahlian;

            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE instruktur SET nama = ?, no_hp = ?, keahlian = ? WHERE id_instruktur = ?");
                $stmt->bind_param("sssi", $nama, $noHp, $keahlian, $id);
                $stmt->execute();
                setFlash('success', 'Instruktur berhasil diperbarui.');
            } else {
                $stmt = $conn->prepare("INSERT INTO instruktur (nama, no_hp, keahlian) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $nama, $noHp, $keahlian);
                $stmt->execute();
                setFlash('success', 'Instruktur berhasil ditambahkan.');
            }

        } elseif ($aksi === 'ubah_role') {
            $idUser = (int) ($_POST['id_user'] ?? 0);
            $role = $_POST['role'] ?? '';

            if (!in_array($role, ['user', 'admin'], true)) {
                throw new RuntimeException('Role tidak valid.');
            }
            if ($idUser === (int) $admin['id_user']) {
                throw new RuntimeException('Kamu tidak bisa mengubah role akunmu sendiri.');
            }

            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id_user = ?");
            $stmt->bind_param("si", $role, $idUser);
            $stmt->execute();
            setFlash('success', 'Role pengguna berhasil diubah.');

        } elseif ($aksi === 'ganti_banner') {
            $file = $_FILES['banner'] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Pilih file banner terlebih dahulu.');
            }
            // Disimpan sebagai img/banner.{jpg|png|webp}, versi lama otomatis dihapus
            simpanGambar($file, '', 'banner', 3);
            setFlash('success', 'Banner berhasil diganti.');

        } elseif ($aksi === 'hapus_jadwal') {
            $id = (int) ($_POST['id_jadwal'] ?? 0);

            // Gagal dengan kode 1451 jika sudah ada pendaftar (foreign key), ditangani di bawah
            $stmt = $conn->prepare("DELETE FROM jadwal WHERE id_jadwal = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new RuntimeException('Jadwal tidak ditemukan.');
            }
            setFlash('success', 'Jadwal berhasil dihapus.');

        } elseif ($aksi === 'hapus_kelas') {
            $id = (int) ($_POST['id_kelas'] ?? 0);

            $stmt = $conn->prepare("SELECT gambar FROM kelas WHERE id_kelas = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $kelasLama = $stmt->get_result()->fetch_assoc();
            if (!$kelasLama) {
                throw new RuntimeException('Kelas tidak ditemukan.');
            }

            // Gagal dengan kode 1451 jika kelas masih punya jadwal (foreign key), ditangani di bawah
            $stmt = $conn->prepare("DELETE FROM kelas WHERE id_kelas = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            $hapusFile = $kelasLama['gambar'];
            setFlash('success', 'Kelas berhasil dihapus.');

        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

        $conn->commit();

        // File gambar kelas dihapus hanya setelah data kelas benar-benar terhapus
        if (!empty($hapusFile) && preg_match('/^kelas-\d+\.(jpg|png|webp)$/', $hapusFile)) {
            @unlink(__DIR__ . '/img/' . $hapusFile);
        }

    } catch (mysqli_sql_exception $e) {
        // Harus ditangkap sebelum RuntimeException (mysqli_sql_exception turunan RuntimeException)
        $conn->rollback();
        if ($e->getCode() === 1452) {
            setFlash('error', 'Kelas atau instruktur yang dipilih tidak valid.');
        } elseif ($e->getCode() === 1451 && $aksi === 'hapus_jadwal') {
            setFlash('error', 'Jadwal tidak bisa dihapus karena sudah ada peserta yang mendaftar.');
        } elseif ($e->getCode() === 1451 && $aksi === 'hapus_kelas') {
            setFlash('error', 'Kelas tidak bisa dihapus karena masih punya jadwal. Hapus jadwalnya terlebih dahulu.');
        } else {
            setFlash('error', 'Terjadi kesalahan database, coba lagi.');
        }
    } catch (RuntimeException $e) {
        $conn->rollback();
        setFlash('error', $e->getMessage());
    }

    header('Location: admin_page.php?page=' . $page);
    exit();
}

// ---------- Data untuk tampilan ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Baris yang sedang diubah (?edit=ID)
$edit = (int) ($_GET['edit'] ?? 0);
$row = null;
$kunci = ['kelas' => 'id_kelas', 'jadwal' => 'id_jadwal', 'instruktur' => 'id_instruktur'];
if ($edit > 0 && isset($kunci[$page])) {
    // Nama tabel dan kolom berasal dari daftar tetap di atas, bukan dari input
    $stmt = $conn->prepare("SELECT * FROM {$page} WHERE {$kunci[$page]} = ?");
    $stmt->bind_param("i", $edit);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
}
$v = function ($k, $d = '') use ($row) {
    return $row[$k] ?? $d;
};

$kelasList = $conn->query("SELECT * FROM kelas ORDER BY id_kelas");
$instrukturList = $conn->query("SELECT * FROM instruktur ORDER BY nama");

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - Cooking Class</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
<div class="admin-layout">

    <aside class="sidebar">
        <p class="sidebar-brand">Admin Panel</p>
        <p class="sidebar-user"><?= h($admin['name']); ?></p>

        <nav>
            <?php foreach ($daftarHalaman as $kode => $label): ?>
                <a href="admin_page.php?page=<?= $kode; ?>" class="<?= $page === $kode ? 'active' : ''; ?>"><?= $label; ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-bottom">
            <nav>
                <a href="home.php">Lihat Halaman User</a>
                <a href="admin_page.php?logout=1">Logout</a>
            </nav>
        </div>
    </aside>

    <main class="admin-main">
        <h1><?= h($daftarHalaman[$page]); ?></h1>

        <?php if ($flash): ?>
            <div class="message <?= h($flash['type']); ?>"><?= h($flash['text']); ?></div>
        <?php endif; ?>

        <?php if ($page === 'kelas'): ?>

            <div class="panel">
                <h2><?= $row ? 'Ubah Kelas' : 'Tambah Kelas'; ?></h2>
                <form method="POST" action="admin_page.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                    <input type="hidden" name="page" value="kelas">
                    <input type="hidden" name="aksi" value="simpan_kelas">
                    <input type="hidden" name="id_kelas" value="<?= (int) $v('id_kelas', 0); ?>">

                    <div class="form-grid">
                        <div class="field full">
                            <label>Nama kelas</label>
                            <input type="text" name="nama_kelas" value="<?= h($v('nama_kelas')); ?>" required>
                        </div>
                        <div class="field full">
                            <label>Deskripsi</label>
                            <textarea name="deskripsi"><?= h($v('deskripsi')); ?></textarea>
                        </div>
                        <div class="field">
                            <label>Harga (Rp)</label>
                            <input type="number" name="harga" min="0" value="<?= h($v('harga')); ?>" required>
                        </div>
                        <div class="field">
                            <label>Durasi (jam)</label>
                            <input type="number" name="durasi_jam" min="1" value="<?= h($v('durasi_jam')); ?>" required>
                        </div>
                        <div class="field full">
                            <label>Gambar kelas</label>
                            <input type="file" name="gambar" accept="image/jpeg,image/png,image/webp">
                            <div class="hint">Rasio 4:5 (mis. 960 x 1200 px), JPG/PNG/WebP, maksimal 2 MB. Foto dengan rasio lain dipotong otomatis dari tengah.<?= $row ? ' Kosongkan jika tidak ingin mengganti gambar.' : ''; ?></div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn"><?= $row ? 'Simpan Perubahan' : 'Tambah Kelas'; ?></button>
                        <?php if ($row): ?>
                            <a href="admin_page.php?page=kelas" class="btn btn-outline">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="panel">
                <h2>Daftar Kelas</h2>
                <div class="table-wrap">
                    <table class="data">
                        <tr><th>Gambar</th><th>Nama</th><th>Harga</th><th>Durasi</th><th></th></tr>
                        <?php foreach ($kelasList as $k): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($k['gambar'])): ?>
                                        <img class="thumb" src="img/<?= h($k['gambar']); ?>" alt="" onerror="this.style.display='none'">
                                    <?php endif; ?>
                                </td>
                                <td><?= h($k['nama_kelas']); ?></td>
                                <td>Rp <?= number_format($k['harga'], 0, ',', '.'); ?></td>
                                <td><?= (int) $k['durasi_jam']; ?> jam</td>
                                <td>
                                    <div class="aksi-baris">
                                        <a class="btn btn-small" href="admin_page.php?page=kelas&edit=<?= (int) $k['id_kelas']; ?>">Ubah</a>
                                        <form method="POST" action="admin_page.php">
                                            <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                                            <input type="hidden" name="page" value="kelas">
                                            <input type="hidden" name="aksi" value="hapus_kelas">
                                            <input type="hidden" name="id_kelas" value="<?= (int) $k['id_kelas']; ?>">
                                            <button type="button" class="btn btn-small btn-danger"
                                                data-confirm-title="Hapus kelas?"
                                                data-confirm-detail="<?= h($k['nama_kelas']); ?>"
                                                data-confirm-note="Kelas yang masih punya jadwal tidak bisa dihapus. Gambar kelas ikut terhapus."
                                                data-confirm-yes="Ya, Hapus"
                                                data-confirm-no="Batal"
                                                data-confirm-danger="1">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

        <?php elseif ($page === 'jadwal'): ?>

            <div class="panel">
                <h2><?= $row ? 'Ubah Jadwal' : 'Tambah Jadwal'; ?></h2>
                <form method="POST" action="admin_page.php">
                    <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                    <input type="hidden" name="page" value="jadwal">
                    <input type="hidden" name="aksi" value="simpan_jadwal">
                    <input type="hidden" name="id_jadwal" value="<?= (int) $v('id_jadwal', 0); ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label>Kelas</label>
                            <select name="id_kelas" required>
                                <option value="">Pilih kelas</option>
                                <?php foreach ($kelasList as $k): ?>
                                    <option value="<?= (int) $k['id_kelas']; ?>" <?= (int) $k['id_kelas'] === (int) $v('id_kelas', 0) ? 'selected' : ''; ?>><?= h($k['nama_kelas']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Instruktur</label>
                            <select name="id_instruktur" required>
                                <option value="">Pilih instruktur</option>
                                <?php foreach ($instrukturList as $i): ?>
                                    <option value="<?= (int) $i['id_instruktur']; ?>" <?= (int) $i['id_instruktur'] === (int) $v('id_instruktur', 0) ? 'selected' : ''; ?>><?= h($i['nama']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Tanggal</label>
                            <input type="date" name="tanggal" value="<?= h($v('tanggal')); ?>" required>
                        </div>
                        <div class="field">
                            <label>Lokasi</label>
                            <input type="text" name="lokasi" value="<?= h($v('lokasi')); ?>">
                        </div>
                        <div class="field">
                            <label>Jam mulai</label>
                            <input type="time" name="jam_mulai" value="<?= h(substr($v('jam_mulai'), 0, 5)); ?>" required>
                        </div>
                        <div class="field">
                            <label>Jam selesai</label>
                            <input type="time" name="jam_selesai" value="<?= h(substr($v('jam_selesai'), 0, 5)); ?>" required>
                        </div>
                        <div class="field">
                            <label>Kuota (sisa slot)</label>
                            <input type="number" name="kuota" min="0" value="<?= h($v('kuota', 10)); ?>" required>
                            <div class="hint">Berkurang otomatis saat peserta mendaftar.</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn"><?= $row ? 'Simpan Perubahan' : 'Tambah Jadwal'; ?></button>
                        <?php if ($row): ?>
                            <a href="admin_page.php?page=jadwal" class="btn btn-outline">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php
                $jadwalList = $conn->query("
                    SELECT j.*, k.nama_kelas, i.nama AS nama_instruktur,
                           (SELECT COUNT(*) FROM pendaftaran p WHERE p.id_jadwal = j.id_jadwal) AS jumlah_daftar
                    FROM jadwal j
                    JOIN kelas k ON k.id_kelas = j.id_kelas
                    JOIN instruktur i ON i.id_instruktur = j.id_instruktur
                    ORDER BY j.tanggal DESC, j.jam_mulai
                ");
            ?>
            <div class="panel">
                <h2>Daftar Jadwal</h2>
                <div class="table-wrap">
                    <table class="data">
                        <tr><th>Kelas</th><th>Tanggal</th><th>Jam</th><th>Instruktur</th><th>Lokasi</th><th>Sisa slot</th><th>Pendaftar</th><th></th></tr>
                        <?php foreach ($jadwalList as $j): ?>
                            <tr>
                                <td><?= h($j['nama_kelas']); ?></td>
                                <td><?= date('d-m-Y', strtotime($j['tanggal'])); ?></td>
                                <td><?= substr($j['jam_mulai'], 0, 5); ?> - <?= substr($j['jam_selesai'], 0, 5); ?></td>
                                <td><?= h($j['nama_instruktur']); ?></td>
                                <td><?= h($j['lokasi'] ?? '-'); ?></td>
                                <td><?= (int) $j['kuota']; ?></td>
                                <td><?= (int) $j['jumlah_daftar']; ?></td>
                                <td>
                                    <div class="aksi-baris">
                                        <a class="btn btn-small" href="admin_page.php?page=jadwal&edit=<?= (int) $j['id_jadwal']; ?>">Ubah</a>
                                        <form method="POST" action="admin_page.php">
                                            <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                                            <input type="hidden" name="page" value="jadwal">
                                            <input type="hidden" name="aksi" value="hapus_jadwal">
                                            <input type="hidden" name="id_jadwal" value="<?= (int) $j['id_jadwal']; ?>">
                                            <button type="button" class="btn btn-small btn-danger"
                                                data-confirm-title="Hapus jadwal?"
                                                data-confirm-detail="<?= h($j['nama_kelas'] . ' - ' . date('d-m-Y', strtotime($j['tanggal']))); ?>"
                                                data-confirm-note="Jadwal yang sudah ada pendaftarnya tidak bisa dihapus."
                                                data-confirm-yes="Ya, Hapus"
                                                data-confirm-no="Batal"
                                                data-confirm-danger="1">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

        <?php elseif ($page === 'instruktur'): ?>

            <div class="panel">
                <h2><?= $row ? 'Ubah Instruktur' : 'Tambah Instruktur'; ?></h2>
                <form method="POST" action="admin_page.php">
                    <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                    <input type="hidden" name="page" value="instruktur">
                    <input type="hidden" name="aksi" value="simpan_instruktur">
                    <input type="hidden" name="id_instruktur" value="<?= (int) $v('id_instruktur', 0); ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label>Nama</label>
                            <input type="text" name="nama" value="<?= h($v('nama')); ?>" required>
                        </div>
                        <div class="field">
                            <label>No. HP</label>
                            <input type="text" name="no_hp" value="<?= h($v('no_hp')); ?>">
                        </div>
                        <div class="field full">
                            <label>Keahlian</label>
                            <input type="text" name="keahlian" value="<?= h($v('keahlian')); ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn"><?= $row ? 'Simpan Perubahan' : 'Tambah Instruktur'; ?></button>
                        <?php if ($row): ?>
                            <a href="admin_page.php?page=instruktur" class="btn btn-outline">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="panel">
                <h2>Daftar Instruktur</h2>
                <div class="table-wrap">
                    <table class="data">
                        <tr><th>Nama</th><th>No. HP</th><th>Keahlian</th><th></th></tr>
                        <?php foreach ($instrukturList as $i): ?>
                            <tr>
                                <td><?= h($i['nama']); ?></td>
                                <td><?= h($i['no_hp'] ?? '-'); ?></td>
                                <td><?= h($i['keahlian'] ?? '-'); ?></td>
                                <td><a class="btn btn-small" href="admin_page.php?page=instruktur&edit=<?= (int) $i['id_instruktur']; ?>">Ubah</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

        <?php elseif ($page === 'pengguna'): ?>

            <?php $userList = $conn->query("SELECT id_user, name, email, role FROM users ORDER BY role = 'admin' DESC, name"); ?>
            <div class="panel">
                <h2>Daftar Pengguna</h2>
                <div class="table-wrap">
                    <table class="data">
                        <tr><th>Nama</th><th>Email</th><th>Role</th><th></th></tr>
                        <?php foreach ($userList as $u): ?>
                            <?php
                                $diriSendiri = (int) $u['id_user'] === (int) $admin['id_user'];
                                $jadiAdmin = $u['role'] !== 'admin';
                            ?>
                            <tr>
                                <td><?= h($u['name']); ?></td>
                                <td><?= h($u['email']); ?></td>
                                <td><span class="badge <?= $jadiAdmin ? 'badge-user' : 'badge-admin'; ?>"><?= h($u['role']); ?></span></td>
                                <td>
                                    <?php if ($diriSendiri): ?>
                                        <span class="muted">Akunmu</span>
                                    <?php else: ?>
                                        <form method="POST" action="admin_page.php">
                                            <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                                            <input type="hidden" name="page" value="pengguna">
                                            <input type="hidden" name="aksi" value="ubah_role">
                                            <input type="hidden" name="id_user" value="<?= (int) $u['id_user']; ?>">
                                            <input type="hidden" name="role" value="<?= $jadiAdmin ? 'admin' : 'user'; ?>">
                                            <button type="button" class="btn btn-small <?= $jadiAdmin ? '' : 'btn-danger'; ?>"
                                                data-confirm-title="Ubah role pengguna?"
                                                data-confirm-detail="<?= h($u['name'] . ' (' . $u['email'] . ')'); ?>"
                                                data-confirm-note="Role akan menjadi: <?= $jadiAdmin ? 'admin' : 'user'; ?>"
                                                data-confirm-yes="Ya, Ubah"
                                                data-confirm-no="Batal"
                                                data-confirm-danger="<?= $jadiAdmin ? '0' : '1'; ?>"><?= $jadiAdmin ? 'Jadikan Admin' : 'Jadikan User'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

        <?php elseif ($page === 'banner'): ?>

            <?php $bannerFile = glob(__DIR__ . '/img/banner.*')[0] ?? null; ?>
            <div class="panel">
                <h2>Banner Halaman Utama</h2>

                <?php if ($bannerFile): ?>
                    <img class="banner-preview" src="img/<?= h(basename($bannerFile)); ?>?v=<?= filemtime($bannerFile); ?>" alt="Banner saat ini">
                <?php else: ?>
                    <p class="muted">Belum ada banner.</p>
                <?php endif; ?>

                <form method="POST" action="admin_page.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= h($csrf); ?>">
                    <input type="hidden" name="page" value="banner">
                    <input type="hidden" name="aksi" value="ganti_banner">

                    <div class="field">
                        <label>Pilih gambar banner baru</label>
                        <input type="file" name="banner" accept="image/jpeg,image/png,image/webp" required>
                        <div class="hint">Ukuran saran 1920 x 400 px (rasio sekitar 4,8:1), JPG/PNG/WebP, maksimal 3 MB. Taruh objek penting di tengah karena sisi kiri dan kanan bisa terpotong di layar sempit.</div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">Ganti Banner</button>
                    </div>
                </form>
            </div>

        <?php endif; ?>
    </main>
</div>

<!-- Pop up konfirmasi (sama dengan home.php, skripnya dipakai bersama) -->
<div class="modal" id="confirm-modal">
    <div class="modal-box">
        <h3 id="modal-title"></h3>
        <p id="modal-detail"></p>
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
