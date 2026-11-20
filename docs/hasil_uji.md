# Hasil uji SIMRAD

Dijalankan: 2026-11-20 11:35:33 (WIB) oleh tests/run_tests.php. Ringkasan: **51 dari 51 lulus**.

| ID | Skenario | Hasil diharapkan | Hasil aktual | Status |
|---|---|---|---|---|
| TC01 | Login dengan kredensial benar | HTTP 200, peran DOKTER | HTTP 200, peran DOKTER | LULUS |
| TC02 | Login dengan kata sandi salah | HTTP 401, kode LOGIN_GAGAL | HTTP 401, LOGIN_GAGAL | LULUS |
| TC03 | Akses worklist tanpa login | HTTP 401, kode BELUM_LOGIN | HTTP 401, BELUM_LOGIN | LULUS |
| TC04 | Dokter membuat order valid | HTTP 201, status ORDERED, nomor akses format ACCYYYYMMDDnnnnn | HTTP 201, ORDERED, ACC2026112000001 | LULUS |
| TC05 | Order dengan prioritas tidak valid | HTTP 422, PRIORITAS_TIDAK_VALID | HTTP 422, PRIORITAS_TIDAK_VALID | LULUS |
| TC06 | Order untuk pasien yang tidak ada | HTTP 404, PASIEN_TIDAK_ADA | HTTP 404, PASIEN_TIDAK_ADA | LULUS |
| TC07 | Radiografer mencoba membuat order (hak akses) | HTTP 403, DILARANG | HTTP 403, DILARANG | LULUS |
| TC08 | Memulai pemeriksaan sebelum dijadwalkan | HTTP 409, STATUS_TIDAK_VALID | HTTP 409, STATUS_TIDAK_VALID | LULUS |
| TC09 | Alur penuh: order, jadwal, mulai, selesai, laporan draft, laporan final | Status akhir REPORTED; UID DICOM berformat 2.25.* dan <= 64 karakter | laporan HTTP 200, status REPORTED, UID 2.25.86079882574549646573651442117121487634 (43 karakter) | LULUS |
| TC10 | Alat tidak sesuai jenis pemeriksaan (CT dikerjakan di alat USG) | HTTP 422, ALAT_TIDAK_SESUAI | HTTP 422, ALAT_TIDAK_SESUAI | LULUS |
| TC11 | Alat berstatus OFFLINE | HTTP 409, ALAT_OFFLINE | HTTP 409, ALAT_OFFLINE | LULUS |
| TC12 | Alat berstatus PERLU_CEK (USG) | HTTP 200 disertai peringatan | HTTP 200, peringatan: Alat US-1 berstatus PERLU_CEK. | LULUS |
| TC13 | Citra tidak layak (decision D3): ulangi pengambilan | ulangi_pengambilan=true, order tetap IN_PROGRESS, tercatat IMAGE_REJECTED | status IN_PROGRESS, event ORDER_CREATED,SCHEDULED,STUDY_STARTED,IMAGE_REJECTED | LULUS |
| TC14 | Dokter mencoba menulis laporan (hak akses) | HTTP 403, DILARANG | HTTP 403, DILARANG | LULUS |
| TC15 | Laporan final tanpa kesimpulan | HTTP 422, KESIMPULAN_WAJIB | HTTP 422, KESIMPULAN_WAJIB | LULUS |
| TC16 | Laporan final tidak diubah; koreksi lewat addendum | Laporan tambahan berjenis ADDENDUM, laporan AWAL tetap FINAL, studi tetap REPORTED | jenis AWAL,ADDENDUM, AWAL FINAL, studi REPORTED | LULUS |
| TC17 | Tandai CITO: sekali berhasil, kedua kali ditolak, setelah REPORTED ditolak | HTTP 200, lalu 409 SUDAH_CITO, lalu 409 STATUS_TIDAK_VALID | HTTP 200, 409 (SUDAH_CITO), 409 (STATUS_TIDAK_VALID) | LULUS |
| TC18 | Worklist: order terbuka di atas order REPORTED; di antara order terbuka, CITO lebih dulu | Semua order terbuka sebelum REPORTED, dan semua CITO terbuka sebelum REGULER terbuka | urutan: CITO/ORDERED, REGULER/ORDERED, REGULER/ORDERED, REGULER/SCHEDULED, REGULER/SCHEDULED, REGULER/IN_PROGRESS... | LULUS |
| TC19 | Dokter hanya melihat order miliknya | Dokter 2 tidak melihat order Dokter 1; detail order dokter lain HTTP 403 | order terlihat: 0, detail HTTP 403 | LULUS |
| TC20 | Admin TI mencoba membuka worklist klinis (B4) | HTTP 403, DILARANG | HTTP 403, DILARANG | LULUS |
| TC21 | Injeksi SQL pada kolom pencarian pasien | Tidak ada kebocoran data atau tabel terhapus | hasil injeksi 0 baris, tabel patient tetap 100 baris (sebelum: 100) | LULUS |
| TC22 | Jejak audit alur penuh lengkap dan berurutan | ORDER_CREATED, SCHEDULED, STUDY_STARTED, STUDY_COMPLETED, SENT_TO_PACS, REPORT_DRAFT_SAVED, REPORT_FINALIZED, ADDENDUM_ADDED | ORDER_CREATED,SCHEDULED,STUDY_STARTED,STUDY_COMPLETED,SENT_TO_PACS,REPORT_DRAFT_SAVED,REPORT_DRAFT_SAVED,REPORT_FINALIZED,ADDENDUM_ADDED | LULUS |
| TC23 | Isi permintaan bukan JSON valid | HTTP 400, JSON_TIDAK_VALID | HTTP 400, JSON_TIDAK_VALID | LULUS |
| TC24 | ID bukan angka pada detail order | HTTP 422, INPUT_TIDAK_VALID | HTTP 422, INPUT_TIDAK_VALID | LULUS |
| TC25 | HL7 ORM^O01: struktur segmen dan field kunci (order reguler CT) | Segmen MSH,PID,ORC,OBR; ORM^O01^ORM_O01, versi 2.5.1; ORC-1 NW, prioritas R; nomor akses di ORC-2/OBR-3; OBR-24 CT | segmen MSH,PID,ORC,OBR; MSH-9 ORM^O01^ORM_O01; ORC-7 ^^^^^R; OBR-24 CT | LULUS |
| TC26 | HL7 ORM^O01: order CITO memakai prioritas S (STAT) | ORC-7 = ^^^^^S | HTTP 200, ORC-7 ^^^^^S | LULUS |
| TC27 | HL7: karakter khusus pada nama pasien di-escape dan tidak menggeser field | Nama berisi & / ^ ~ \ tampil sebagai \T\ \F\ \S\ \R\ \E\; PID-8 tetap jenis kelamin | PID-5 = Budi^O\T\Brien\F\Tes\S\X\R\Y\E\Z; PID-8 = M | LULUS |
| TC28 | HL7 ORU^R01 untuk order REPORTED (laporan awal + addendum) | ORU^R01^ORU_R01; OBR-25 = F; 4 segmen OBX bernilai F berisi temuan dan kesimpulan | OBX=4; nilai: Tidak tampak lesi. / Normal. / Koreksi. / Addendum: normal. | LULUS |
| TC29 | HL7 ORU^R01 untuk order yang belum punya laporan final | HTTP 409, LAPORAN_BELUM_FINAL | HTTP 409, LAPORAN_BELUM_FINAL | LULUS |
| TC30 | FHIR Bundle order REPORTED: sumber daya, prioritas, UID DICOM, status laporan | transaction; Patient, ServiceRequest(stat,completed), ImagingStudy (urn:oid, 5 instance), DiagnosticReport final dan appended | Patient,ServiceRequest,ImagingStudy,DiagnosticReport,DiagnosticReport; SR stat/completed; DR final,appended | LULUS |
| TC31 | FHIR Bundle: semua referensi urn:uuid menunjuk entri yang ada; pengiriman ulang aman (ifNoneExist) | Tidak ada referensi menggantung; setiap entri memiliki request.ifNoneExist | 9 referensi, 0 menggantung; entri tanpa ifNoneExist: 0 | LULUS |
| TC32 | FHIR Bundle order yang baru dibuat (belum diperiksa) | Hanya Patient dan ServiceRequest (active, routine); tanpa ImagingStudy dan DiagnosticReport | Patient,ServiceRequest; active/routine | LULUS |
| TC33 | Hak akses pesan integrasi: Admin TI dan dokter lain ditolak (B4) | HTTP 403 untuk Admin TI (HL7 dan FHIR) dan Dokter 2 pada order Dokter 1 | Admin: 403/403; Dokter 2: 403/403 | LULUS |
| TC34 | Pesan integrasi: order tidak ada dan ID bukan angka | HTTP 404 (ORDER_TIDAK_ADA) dan HTTP 422 (INPUT_TIDAK_VALID) | HTTP 404, 422 | LULUS |
| TC35 | Registrasi pasien lewat API: data valid, nomor RM otomatis | HTTP 201; nomor RM berformat P + angka; baris di basis data sama dengan masukan | HTTP 201, no. RM P0101 | LULUS |
| TC36 | Registrasi pasien: validasi masukan | 422 untuk tanggal masa depan, tanggal tidak ada (30 Feb), jenis kelamin salah, NIK 15 digit, nama kosong | 422 TANGGAL_LAHIR_TIDAK_VALID; 422 TANGGAL_LAHIR_TIDAK_VALID; 422 JENIS_KELAMIN_TIDAK_VALID; 422 NIK_TIDAK_VALID; 422 NAMA_TIDAK_VALID | LULUS |
| TC37 | Registrasi pasien: duplikat NIK dan duplikat nomor RM | HTTP 409 NIK_SUDAH_TERDAFTAR dan HTTP 409 NO_RM_SUDAH_ADA | HTTP 409 NIK_SUDAH_TERDAFTAR; HTTP 409 NO_RM_SUDAH_ADA | LULUS |
| TC38 | Registrasi pasien: hak akses (dokter dan radiografer boleh; radiolog dan Admin TI ditolak) | Radiografer HTTP 201; Radiolog dan Admin TI HTTP 403 | Radiografer 201, Radiolog 403, Admin TI 403 | LULUS |
| TC39 | HL7 ADT^A04 masuk: registrasi pasien dari HIS dan ACK AA; pengiriman ulang tidak menggandakan | ACK AA; data pasien sama dengan isi pesan; kirim ulang tetap AA dan jumlah baris tidak bertambah | ACK MSA/AA/MSG-A1/Pasien terdaftar: P9452; kirim ulang AA, baris=1 | LULUS |
| TC40 | HL7 ORM^O01 masuk: order dari HIS (CITO lewat ORC-7) dan ACK AA | ACK AA berisi nomor akses; order berprioritas CITO, pasien dan pemeriksaan sesuai pesan | MSA/AA/MSG-O1/Order diterima; nomor akses ACC2026112000013; prioritas CITO | LULUS |
| TC41 | HL7 masuk: pesan salah dibalas ACK AE/AR; hak akses dijaga | Pasien tak dikenal AE; kode pemeriksaan tak dikenal AE; ORU AR; ORC-1 bukan NW AR; tanpa MSH HTTP 422; Radiolog HTTP 403 | AE, AE, AR, AR, HTTP 422, HTTP 403 | LULUS |
| TC42 | Simulasi Modality Worklist (C-FIND): hanya order SCHEDULED, atribut DICOM sesuai data order | Order ORDERED tidak muncul; setelah dijadwalkan muncul dengan PatientID, PatientName (Keluarga^Nama), AccessionNumber, Modality CT, AET CT_ROOM1; filter modality dan hak akses benar | sebelum dijadwalkan ada=false; PatientName Nurhaliza^Siti; AET CT_ROOM1; filter MR ada=false; Radiolog 403; modality XX 422 | LULUS |
| TC43 | INTEGRASI HIS -> RIS -> PACS tanpa data hilang (ADT, ORM, MWL, akuisisi 7 citra, C-STORE simulasi, laporan, ORU) | Setiap atribut pasien dan setiap UID instance di PACS sama dengan di RIS (7 dari 7, tanpa selisih); ORU memuat nomor akses, nomor RM, dan kesimpulan | instance RIS=7, PACS=7, selisih=0; atribut sama=true; ORU memuat acc/RM/kesimpulan=true | LULUS |
| TC44 | PACS offline: studi tidak hilang, kegagalan dicatat, kirim ulang berhasil dan idempoten | complete tetap berhasil (pacs=gagal), PACS 0 instance, event PACS_SEND_FAILED; setelah online kirim ulang menghasilkan 4 dari 4 instance; kirim ulang kedua tetap 4 | saat offline: pacs=gagal, studi=COMPLETED, PACS=0; sesudah kirim ulang: PACS=4; kirim ulang kedua: PACS=4 | LULUS |
| TC45 | Hak akses dan status pada PACS simulasi | Dokter dan Radiolog tidak boleh mengubah status PACS (403); kirim ulang oleh dokter 403; studi belum selesai ditolak 409 | Dokter 403, Radiolog 403, status online=true, kirim ulang dokter 403, studi belum selesai 409 | LULUS |
| TC46 | Detail order memuat perbandingan instance RIS dan PACS | di_ris = di_pacs = 7 dan lengkap = true untuk alur TC43 | RIS=7, PACS=7, lengkap=true | LULUS |
| IT01 | TAT CITO dari 2 order selesai (50 dan 70 menit) | rata-rata 60,0 menit; maks 70,0; dalam target 50% | {"target_menit":60,"jumlah_selesai":2,"rata_menit":60,"maks_menit":70,"persen_dalam_target":50} | LULUS |
| IT02 | TAT reguler dari 2 order selesai (120 dan 90 menit); batas 120 tidak dihitung dalam target | rata-rata 105,0 menit; dalam target 50% | {"target_menit":120,"jumlah_selesai":2,"rata_menit":105,"maks_menit":120,"persen_dalam_target":50} | LULUS |
| IT03 | Rata-rata per tahap (tunggu, pemeriksaan, pembacaan) dari 4 order | tunggu 25,0; pemeriksaan 27,5; pembacaan 30,0 menit (hitung manual: 100/4, 110/4, 120/4) | {"rata_tunggu_menit":25,"rata_pemeriksaan_menit":27.5,"rata_pembacaan_menit":30} | LULUS |
| IT04 | Order CITO terbuka sejak 10:00 dihitung melewati target pada 12:30; status per tahap benar | 1 CITO terbuka lewat target; REPORTED=4, ORDERED=1 | {"lewat":{"CITO":1,"REGULER":0},"status":{"ORDERED":1,"SCHEDULED":0,"IN_PROGRESS":0,"COMPLETED":0,"REPORTED":4}} | LULUS |
| IT05 | Angka desimal JSON tidak bergantung php.ini (serialize_precision tinggi seperti di XAMPP) | json_encode([105.6]) = [105.6] walau PHP dijalankan dengan serialize_precision=50 | keluaran: [105.6] | LULUS |
