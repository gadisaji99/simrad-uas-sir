# Catatan bug dan masalah SIMRAD

Skala keparahan: **Critical** (data salah atau hilang, kebocoran, aplikasi tidak bisa dipakai), **Major** (fitur utama gagal atau kekurangan keamanan/cakupan yang berarti), **Minor** (fitur jalan tetapi tidak nyaman atau berisiko; termasuk masalah tampilan, ditandai "kosmetik"). Belum ada bug berlevel Critical yang ditemukan.

## A. Ditemukan dan sudah diperbaiki
| ID | Keparahan | Temuan | Cara ditemukan | Perbaikan dan verifikasi |
|---|---|---|---|---|
| BUG-001 | Major | Menyelesaikan pemeriksaan (`studies.complete`) menghasilkan galat server 500 bila ekstensi PHP `mbstring` tidak aktif (memakai `mb_substr`). | Uji TC09, TC16, TC22 pertama kali dijalankan di lingkungan pengembang (mbstring tidak aktif). | `Workflow::potong()` memakai mbstring bila ada, bila tidak memakai ekspresi reguler UTF-8. Seluruh uji lulus. Di XAMPP mbstring aktif, sehingga galat ini tidak muncul di komputer penulis. |
| BUG-002 | Minor | Seed berisi komentar `/*M!999999\- enable the sandbox mode */` dari versi MariaDB yang lebih baru; berisiko menggagalkan impor di phpMyAdmin atau MariaDB lama. | Pemeriksaan kompatibilitas sebelum impor. | Baris dibuang. Impor di MariaDB 10.4.28 berhasil (216 kueri). |
| BUG-003 | Minor (kosmetik) | Kolom "Aksi" pada tabel tidak rata (`display:flex` diterapkan pada sel tabel). | Tangkapan layar Safari. | Tombol dibungkus `div`. Terlihat rata pada tangkapan layar berikutnya. |
| BUG-004 | Minor (kosmetik) | Kolom pencarian terpotong (placeholder tidak terbaca utuh). | Tangkapan layar Safari. | `min-width` kolom pencarian dinaikkan. |
| BUG-005 | Minor | Daftar kerja menaruh order REPORTED di atas order yang masih menunggu. | Tangkapan layar Safari. | Urutan: order terbuka dahulu, lalu CITO, lalu waktu. Uji TC18 disesuaikan; lulus. |
| BUG-006 | Minor | Sesi PHP mengunci permintaan paralel dari sesi yang sama sehingga permintaan mengantre. Percobaan: 4 permintaan serentak, masing-masing menunggu 0,3 detik, selesai dalam 1,27 detik. | Percobaan terarah saat menyiapkan uji beban. | `Auth::user()` memanggil `session_write_close()` setelah membaca sesi. Percobaan yang sama: sekitar 0,6 detik. Diukur di lingkungan pengembang berinti satu; manfaat pada beban nyata belum diukur. |
| BUG-007 | Minor | Angka desimal pada JSON (rata-rata TAT) tampil dengan puluhan digit (`105.5999999999999943…`) di komputer penulis, karena `php.ini` XAMPP memakai `serialize_precision` tinggi. Tampilan dashboard tidak terpengaruh (dibulatkan di layar), tetapi keluaran API bergantung pada pengaturan server. | Keluaran `db/demo_seed.php` di Terminal penulis (XAMPP macOS). | `ini_set('serialize_precision', '-1')` di `src/bootstrap.php`. Diverifikasi dengan meniru pengaturan (`php -d serialize_precision=50`): sebelum `105.599999999999994…`, sesudah `105.6`. Uji pelindung IT05 ditambahkan.
| BUG-008 | Minor | Pesan HL7 ADT^A04 yang dikirim ulang (nomor RM sama) dijawab `AE "NIK sudah terdaftar"`, bukan `AA` (duplikat); NIK diperiksa sebelum nomor RM. | Uji manual sebelum kasus uji ditulis. | Nomor RM diperiksa lebih dulu. Diverifikasi TC39 (kirim ulang tetap AA, jumlah baris tidak bertambah). |
| BUG-009 | Minor (kosmetik) | Stempel waktu hasil uji beban 20 pengguna memakai zona waktu bawaan `php.ini` XAMPP, enam jam di belakang WIB (skrip belum menetapkan zona waktu). | Pembacaan hasil di komputer penulis: 05:35:54, padahal uji fungsional 11:35:33 WIB. | `date_default_timezone_set('Asia/Jakarta')` di `tests/load_multiuser.php`. Perbaikan ada di paket berikutnya; hasil yang sudah ada tidak diulang. |

## B. Kesalahan pada kasus uji (bukan pada aplikasi)
| ID | Temuan | Koreksi |
|---|---|---|
| UJI-001 | Harapan IT03 salah hitung (rata-rata tunggu 17,5 menit), padahal hitungan manual dari empat order adalah 25,0 (100/4). | Harapan diperbaiki ke 25,0; rata-rata pemeriksaan 27,5; pembacaan 30,0. Aplikasi sudah benar sejak awal. |
| UJI-002 | NIK pada kasus uji registrasi (TC35, TC37-TC40) hanya 14 digit, padahal harus 16. Aplikasi menolaknya dengan benar (`NIK_TIDAK_VALID`). | Panjang NIK uji dikoreksi; aplikasi tidak diubah. |
| UJI-003 | TC21 menuntut tepat 100 pasien dan UI04 menuntut 4 penanda status; keduanya usang setelah fitur baru (pasien tambahan, chip PACS). | Pembanding dibuat relatif (jumlah sebelum dan sesudah); UI04 diperbarui menjadi 5 penanda. |

## C. Catatan pemasangan (bukan bug aplikasi)
- Folder di `htdocs` bernama `simrad 2` lalu `simrad ` (spasi di akhir) menyebabkan HTTP 404. Diperbaiki dengan mengganti nama menjadi persis `simrad`.
- Basis data sempat berisi perubahan dari luar (kata sandi akun berbeda dari seed). Diperbaiki dengan mengimpor ulang `schema.sql` dan `seed.sql`.

## D. Masalah yang diketahui dan belum diperbaiki
| ID | Keparahan | Masalah | Dampak dan saran |
|---|---|---|---|
| KNOWN-01 | Major (keamanan) | Tidak ada pembatasan percobaan login. | Rentan tebak kata sandi. Tambahkan penundaan atau penguncian sementara setelah beberapa percobaan gagal. |
| KNOWN-02 | Major (keamanan) | Tidak ada token CSRF; hanya cookie `SameSite=Lax`. | Perlindungan parsial. Tambahkan token CSRF pada permintaan yang mengubah data. |
| KNOWN-03 | Minor | Tidak ada batas waktu sesi yang diatur sendiri (memakai bawaan PHP). | Atur masa berlaku sesi dan logout otomatis. |
| KNOWN-04 | Minor (keamanan) | Konfigurasi bawaan memakai `root` tanpa kata sandi (bawaan XAMPP). | Hanya untuk lokal. Di luar lokal wajib pengguna basis data khusus (variabel lingkungan). |
| KNOWN-05 | Major (cakupan) | Citra DICOM disimulasikan; tidak ada penyimpanan DICOM nyata, C-STORE, atau viewer yang tersambung. | Bukan klaim fungsi PACS. |
| KNOWN-06 | Major (cakupan) | Modul PACS/MWL dari dosen tidak diadaptasi; MWL dan PACS pada SIMRAD adalah simulasi buatan sendiri. | Integrasi dengan PACS nyata (Orthanc, DCM4CHE, atau Conquest) belum dibuktikan. |
| KNOWN-07 | Minor | Pesan HL7 dan bundle FHIR belum diuji terhadap penerima nyata (hanya divalidasi terhadap model FHIR R4B dan diuji format). Identifier SATUSEHAT belum dipetakan. | Uji ke server FHIR dan petakan identifier sesuai panduan SATUSEHAT yang berlaku. |
| KNOWN-08 | Minor | Uji UI otomatis memakai jsdom (tanpa render visual); tampilan hanya dicoba di Safari. | Uji manual di peramban lain. |
| KNOWN-09 | Minor | Uji beban memakai satu sesi bersama dan satu mesin; tidak mewakili beban rumah sakit. | Anggap hasilnya indikatif saja. |
| KNOWN-10 | Major (privasi) | Registrasi pasien belum tercatat di log audit, karena `workflow_event` pada B3 terikat ke order. | Kurang sesuai semangat UU PDP Pasal 31. Saran: tabel audit umum untuk semua aksi data pribadi. |
| KNOWN-11 | Minor | ORM^O01 yang sama dikirim dua kali membuat dua order (nomor order pemesan dari HIS tidak disimpan). | Simpan placer order number dan tolak duplikat. |
| KNOWN-12 | Minor | Tidak ada akun layanan untuk HIS; pesan HL7 masuk memakai sesi dokter atau radiografer. Prioritas hanya dibaca dari ORC-7 (komponen 6); posisi lain dianggap rutin. | Tambahkan peran sistem eksternal dan pembacaan prioritas dari TQ1/OBR sesuai profil HL7 yang dipakai. |
| KNOWN-13 | Minor | Rancangan B2 (decision D2) menyebut antre ulang otomatis 3 kali disertai notifikasi bila pengiriman ke PACS gagal; prototipe hanya mencatat kegagalan dan radiografer mengirim ulang secara manual. | Studi tidak hilang (diuji TC44), tetapi tidak ada upaya ulang otomatis maupun notifikasi. Saran: antrean pengiriman dengan penjadwal (cron) dan notifikasi di dashboard. |
