# Hasil uji integrasi 50 studi (HIS -> RIS -> PACS simulasi)

Dijalankan: 2026-11-21 12:02:34 (WIB) oleh tests/uji_50_studi.php. Lingkungan: Darwin, PHP 8.2.4, server http://localhost/simrad/public/api.php. Benih acak tetap 2026; jumlah studi 50; data seluruhnya fiktif.

**Ringkasan: 50 dari 50 studi lolos rekonsiliasi penuh.**

| Ukuran | Hasil |
|---|---|
| Studi lolos rekonsiliasi penuh (semua langkah dan perbandingan RIS-PACS) | 50 dari 50 |
| Jenis modalitas | CT, MR, DX, US (bergilir), dengan 10 studi CITO |
| Total instance DICOM simulasi di RIS | 1616 |
| Total instance di PACS simulasi | 1616 |
| Selisih UID instance (RIS dibanding PACS, dua arah) | 0 |
| Baris PACS dengan atribut berbeda (nomor akses, nomor RM, nama, modalitas) | 0 |
| Studi tampil di Modality Worklist setelah dijadwalkan | 50 dari 50 |
| Pesan ORU memuat nomor akses, nomor RM, dan kesimpulan | 50 dari 50 |
| Durasi total | 1.7 detik |

## Waktu respons per langkah (milidetik)

| Langkah | Jumlah panggilan | Rerata | Median | p95 | Maks |
|---|---|---|---|---|---|
| ADT^A04 (registrasi pasien) | 50 | 3.6 | 3.1 | 6.4 | 8.6 |
| ORM^O01 (order) | 50 | 3.9 | 3.5 | 7.7 | 11.4 |
| Jadwalkan order | 50 | 2.9 | 2.8 | 3.4 | 4.3 |
| Modality Worklist (MWL) | 50 | 2.8 | 2.6 | 4.3 | 5.9 |
| Mulai pemeriksaan | 50 | 3.3 | 3.3 | 3.9 | 4.4 |
| Selesai dan kirim ke PACS | 50 | 9.8 | 9.8 | 14.3 | 15.1 |
| Laporan final | 50 | 3.7 | 3.6 | 4.3 | 4.5 |
| ORU^R01 (hasil ke HIS) | 50 | 3.0 | 2.8 | 3.6 | 7.7 |

Tidak ada studi yang gagal.
