const { JSDOM } = require('jsdom');
const fs = require('fs');
// Server uji: php -S 127.0.0.1:8099 -t public (dari folder simrad); ubah BASE jika memakai alamat lain.
const BASE = process.env.SIMRAD_UI_BASE || 'http://127.0.0.1:8099/';
const html = fs.readFileSync(require('path').join(__dirname, '..', 'public', 'index.html'), 'utf8');
let pass = 0, fail = 0; const log = [];
const ok = (c, m) => { (c ? pass++ : fail++); log.push((c ? 'LULUS ' : 'GAGAL ') + m); };
const sleep = ms => new Promise(r => setTimeout(r, ms));

async function sesi(username, password) {
  const jar = {};
  const dom = new JSDOM(html, { url: BASE + 'index.html', runScripts: 'dangerously', pretendToBeVisual: true,
    beforeParse(w) {
      w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
      w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
      w.fetch = async (url, opt = {}) => {
        const h = Object.assign({}, opt.headers || {});
        if (Object.keys(jar).length) h.Cookie = Object.entries(jar).map(([k, v]) => k + '=' + v).join('; ');
        const res = await fetch(new URL(url, BASE).href, Object.assign({}, opt, { headers: h }));
        for (const c of res.headers.getSetCookie?.() || []) { const [kv] = c.split(';'); const i = kv.indexOf('='); jar[kv.slice(0, i)] = kv.slice(i + 1); }
        return res;
      };
    } });
  const w = dom.window, d = w.document;
  const until = async (f, ms = 4000) => { const t = Date.now(); while (Date.now() - t < ms) { try { const v = f(); if (v) return v; } catch {} await sleep(40); } return null; };
  await until(() => d.querySelector('form.login'));
  if (username) {
    d.querySelector('#u').value = username; d.querySelector('#p').value = password;
    d.querySelector('form.login').dispatchEvent(new w.Event('submit', { cancelable: true, bubbles: true }));
    await until(() => d.querySelector('.cards'));
  }
  return { w, d, until, dom, jar };
}
const klik = (w, n) => n.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
const btn = (root, teks) => [...root.querySelectorAll('button')].find(b => b.textContent.trim() === teks);

(async () => {
  // 1. Belum login -> formulir login; login salah -> pesan galat
  let s = await sesi();
  ok(!!s.d.querySelector('form.login'), 'UI01 formulir login tampil saat belum login');
  s.d.querySelector('#u').value = 'd001'; s.d.querySelector('#p').value = 'salah';
  s.d.querySelector('form.login').dispatchEvent(new s.w.Event('submit', { cancelable: true, bubbles: true }));
  await s.until(() => s.d.querySelector('.err').textContent.length > 0);
  ok(/salah/i.test(s.d.querySelector('.err').textContent), 'UI02 login salah menampilkan pesan galat');
  s.w.close();

  // 2. Dokter: dashboard, KPI, chips, order baru
  s = await sesi('d001', 'simrad2026');
  ok(s.d.querySelectorAll('.card').length === 4, 'UI03 empat kartu KPI tampil');
  ok(s.d.querySelectorAll('.chip').length === 5, 'UI04 lima penanda tampil: empat alat dan PACS (simulasi)');
  ok(/PERLU CEK/.test(s.d.querySelector('.chips').textContent), 'UI05 status alat USG PERLU CEK tampil sebagai teks');
  ok(!!s.d.querySelector('#btnNew'), 'UI06 dokter melihat tombol Order baru');
  const awal = s.d.querySelectorAll('tbody tr').length;
  klik(s.w, s.d.querySelector('#btnNew'));
  const dlg = await s.until(() => s.d.querySelector('dialog[open] select[size]') && s.d.querySelector('dialog[open] select[size]').options.length ? s.d.querySelector('dialog[open]') : null);
  ok(!!dlg, 'UI07 dialog order baru terbuka dengan daftar pasien');
  const pas = dlg.querySelector('select[size]'); pas.selectedIndex = 0;
  const pri = [...dlg.querySelectorAll('select')].pop(); pri.value = 'REGULER';
  klik(s.w, btn(dlg, 'Kirim order'));
  await s.until(() => s.d.querySelectorAll('tbody tr').length === awal + 1);
  ok(s.d.querySelectorAll('tbody tr').length === awal + 1, 'UI08 order baru muncul di worklist');
  // kirim order tanpa pasien dipilih -> galat ditampilkan, dialog tetap
  klik(s.w, s.d.querySelector('#btnNew'));
  const d2 = await s.until(() => s.d.querySelector('dialog[open]'));
  d2.querySelector('select[size]').replaceChildren();
  klik(s.w, btn(d2, 'Kirim order'));
  await s.until(() => d2.querySelector('.err').textContent.length > 0);
  ok(/Pilih pasien/.test(d2.querySelector('.err').textContent) && d2.hasAttribute('open'), 'UI09 order tanpa pasien ditolak dengan pesan jelas');
  klik(s.w, btn(d2, 'Batal'));
  // tandai CITO dengan konfirmasi
  const baris = [...s.d.querySelectorAll('tbody tr')].find(tr => /REGULER/.test(tr.textContent) && /ORDERED/.test(tr.textContent));
  klik(s.w, btn(baris, 'Tandai CITO'));
  const kon = await s.until(() => s.d.querySelector('dialog[open]'));
  ok(/tidak dapat dikembalikan/.test(kon.textContent) && !!btn(kon, 'Batal') && !!btn(kon, 'Ya, tandai CITO'), 'UI10 dialog konfirmasi CITO dengan Batal dan Ya');
  const id = baris.dataset.id;
  klik(s.w, btn(kon, 'Ya, tandai CITO'));
  await s.until(() => { const r = s.d.querySelector('tr[data-id="' + id + '"]'); return r && /CITO/.test(r.firstChild.textContent); });
  ok(/CITO/.test(s.d.querySelector('tr[data-id="' + id + '"]').firstChild.textContent), 'UI11 prioritas berubah menjadi CITO setelah konfirmasi');
  ok(s.d.querySelector('tbody tr').firstChild.textContent === 'CITO', 'UI12 baris CITO diurutkan paling atas');
  // detail + jejak audit
  klik(s.w, s.d.querySelector('tr[data-id="' + id + '"]'));
  await s.until(() => s.d.querySelector('.detail ol li'));
  ok(/PRIORITY_TO_CITO/.test(s.d.querySelector('.detail').textContent), 'UI13 jejak audit mencatat PRIORITY_TO_CITO');
  const orderId = id; s.w.close();

  // 3. Radiografer: jadwalkan -> mulai -> selesai (ulangi pengambilan lalu berhasil)
  s = await sesi('r001', 'simrad2026');
  ok(!s.d.querySelector('#btnNew'), 'UI14 radiografer tidak melihat tombol Order baru');
  let tr = s.d.querySelector('tr[data-id="' + orderId + '"]');
  klik(s.w, btn(tr, 'Jadwalkan')); let k = await s.until(() => s.d.querySelector('dialog[open]')); klik(s.w, btn(k, 'Jadwalkan'));
  await s.until(() => /SCHEDULED/.test(s.d.querySelector('tr[data-id="' + orderId + '"]').textContent));
  tr = s.d.querySelector('tr[data-id="' + orderId + '"]');
  ok(/SCHEDULED/.test(tr.textContent) && !!btn(tr, 'Mulai'), 'UI15 status SCHEDULED dan tombol Mulai muncul');
  klik(s.w, btn(tr, 'Mulai')); k = await s.until(() => s.d.querySelector('dialog[open]'));
  const alat = k.querySelector('select');
  const jenis = require('child_process').execFileSync('mariadb', ['-uroot', '-N', 'simrad', '-e', 'SELECT pr.jenis_modalitas FROM imaging_order o JOIN `procedure` pr USING(procedure_id) WHERE o.order_id=' + Number(orderId)]).toString().trim();
  const semuaSesuai = alat.options.length > 0 && [...alat.options].every(o => o.textContent.startsWith(jenis + '-'));
  ok(semuaSesuai, 'UI16 daftar alat hanya berisi alat berjenis ' + jenis + ' (sesuai pemeriksaan): ' + [...alat.options].map(o => o.textContent.split(' ')[0]).join(', '));
  klik(s.w, btn(k, 'Mulai'));
  await s.until(() => /IN PROGRESS/.test(s.d.querySelector('tr[data-id="' + orderId + '"]').textContent));
  tr = s.d.querySelector('tr[data-id="' + orderId + '"]');
  ok(/IN PROGRESS/.test(tr.textContent), 'UI17 status IN PROGRESS setelah mulai');
  klik(s.w, btn(tr, 'Selesai')); k = await s.until(() => s.d.querySelector('dialog[open]'));
  const cb = k.querySelector('input[type=checkbox]'); cb.checked = false; cb.dispatchEvent(new s.w.Event('change'));
  ok(!!btn(k, 'Ulangi pengambilan'), 'UI18 label tombol berubah menjadi Ulangi pengambilan bila citra tidak layak');
  klik(s.w, btn(k, 'Ulangi pengambilan')); await sleep(600);
  ok(/IN PROGRESS/.test(s.d.querySelector('tr[data-id="' + orderId + '"]').textContent), 'UI19 citra tidak layak: status tetap IN PROGRESS');
  tr = s.d.querySelector('tr[data-id="' + orderId + '"]'); klik(s.w, btn(tr, 'Selesai')); k = await s.until(() => s.d.querySelector('dialog[open]'));
  k.querySelector('input[type=number]').value = 3; klik(s.w, btn(k, 'Selesaikan'));
  await s.until(() => /COMPLETED/.test(s.d.querySelector('tr[data-id="' + orderId + '"]').textContent));
  ok(/COMPLETED/.test(s.d.querySelector('tr[data-id="' + orderId + '"]').textContent), 'UI20 status COMPLETED setelah diselesaikan');
  s.w.close();

  // 4. Radiolog: laporan final dengan kesimpulan wajib, lalu addendum
  s = await sesi('rad01', 'simrad2026');
  tr = s.d.querySelector('tr[data-id="' + orderId + '"]'); klik(s.w, btn(tr, 'Laporan')); k = await s.until(() => s.d.querySelector('dialog[open]'));
  klik(s.w, btn(k, 'Finalkan')); await s.until(() => k.querySelector('.err').textContent.length > 0);
  ok(/Kesimpulan/i.test(k.querySelector('.err').textContent), 'UI21 laporan final tanpa kesimpulan ditolak dengan pesan');
  const ta = k.querySelectorAll('textarea'); ta[0].value = 'Tidak tampak kelainan.'; ta[1].value = 'Normal.';
  klik(s.w, btn(k, 'Finalkan'));
  await s.until(() => /REPORTED/.test(s.d.querySelector('tr[data-id="' + orderId + '"]').textContent));
  tr = s.d.querySelector('tr[data-id="' + orderId + '"]');
  ok(/REPORTED/.test(tr.textContent) && !!btn(tr, 'Addendum') && !btn(tr, 'Tandai CITO'), 'UI22 status REPORTED, tersedia Addendum, Tandai CITO hilang');
  klik(s.w, tr); await s.until(() => s.d.querySelector('.detail ol li'));
  ok(/REPORT_FINALIZED/.test(s.d.querySelector('.detail').textContent) && /Normal\./.test(s.d.querySelector('.detail').textContent), 'UI23 detail memuat laporan final dan jejak audit');
  const kpi = s.d.querySelector('.cards').textContent;
  ok(/CITO \(rata-rata\)/i.test(kpi) && /selesai/.test(kpi), 'UI24 kartu TAT CITO menampilkan data setelah laporan final');
  s.w.close();

  // 5. Admin TI: hanya agregat, tanpa worklist
  s = await sesi('adm01', 'simrad2026');
  ok(!s.d.querySelector('table') && /tidak membuka data klinis/.test(s.d.body.textContent), 'UI25 Admin TI tidak melihat worklist, hanya agregat');
  s.d.querySelector('#btnLogout').dispatchEvent(new s.w.MouseEvent('click', { bubbles: true }));
  await s.until(() => s.d.querySelector('form.login'));
  ok(!!s.d.querySelector('form.login'), 'UI26 tombol Keluar mengembalikan ke login');
  s.w.close();

  // 6. XSS: nama pasien berisi HTML harus tampil sebagai teks
  require('child_process').execSync(`mariadb -uroot simrad -e "UPDATE patient SET nama='<img src=x onerror=window.__xss=1>Uji' WHERE patient_id=1"`);
  s = await sesi('d001', 'simrad2026');
  klik(s.w, s.d.querySelector('#btnNew')); k = await s.until(() => s.d.querySelector('dialog[open] select[size]') && s.d.querySelector('dialog[open]'));
  await s.until(() => k.querySelector('select[size]').options.length);
  const teksOpsi = [...k.querySelector('select[size]').options].map(o => o.textContent).join('|');
  ok(!s.d.querySelector('img') && s.w.__xss !== 1 && teksOpsi.includes('<img'), 'UI27 HTML pada nama pasien tidak dieksekusi (tampil sebagai teks)');
  require('child_process').execSync(`mariadb -uroot simrad -e "UPDATE patient SET nama=(SELECT nama FROM (SELECT 'Budi Santoso' nama) t) WHERE patient_id=1"`);
  s.w.close();

  // 7. Registrasi pasien, Modality Worklist, dan status PACS
  const { execFileSync } = require('child_process');
  const sqlTeks = q => execFileSync('mariadb', ['-uroot', '-N', 'simrad', '-e', q]).toString().trim();
  const panggil = async (sess, route, body) => { const r = await sess.w.fetch('api.php?r=' + route, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); return r.json(); };
  const dialogTerbuka = (sess, teks) => sess.until(() => [...sess.d.querySelectorAll('dialog[open]')].find(x => x.textContent.includes(teks)));

  s = await sesi('d001', 'simrad2026');
  klik(s.w, s.d.querySelector('#btnPasien')); let k7 = await dialogTerbuka(s, 'Registrasi pasien baru');
  klik(s.w, btn(k7, 'Daftarkan')); await s.until(() => k7.querySelector('.err').textContent.length > 0);
  ok(/Nama wajib/.test(k7.querySelector('.err').textContent) && k7.hasAttribute('open'), 'UI28 registrasi tanpa data ditolak dengan pesan jelas, dialog tetap terbuka');
  const nikUi = '3581' + String(Math.floor(Math.random() * 1e12)).padStart(12, '0');
  const ins = k7.querySelectorAll('input'); ins[0].value = 'Uji Registrasi Layar'; ins[1].value = '1991-05-05'; k7.querySelector('select').value = 'P'; ins[2].value = nikUi;
  klik(s.w, btn(k7, 'Daftarkan')); const info = await dialogTerbuka(s, 'Pasien terdaftar');
  const barisDb = sqlTeks("SELECT CONCAT(no_rm,'|',nama,'|',tanggal_lahir,'|',jenis_kelamin) FROM patient WHERE nik='" + nikUi + "'");
  ok(!!info && /nomor RM P\d+/.test(info.textContent) && barisDb.endsWith('|Uji Registrasi Layar|1991-05-05|P'), 'UI29 registrasi berhasil: nomor RM ditampilkan dan data tersimpan (' + barisDb + ')');
  s.w.close();

  const dok = await sesi('d001', 'simrad2026'), rg = await sesi('r001', 'simrad2026');
  const prCT7 = Number(sqlTeks("SELECT procedure_id FROM `procedure` WHERE jenis_modalitas='CT' ORDER BY kode LIMIT 1")), alatCT = Number(sqlTeks("SELECT modality_id FROM modality WHERE kode_alat='CT-1'"));
  const o1 = await panggil(dok, 'orders.create', { patient_id: 2, procedure_id: prCT7, prioritas: 'CITO' });
  const mwlSebelum = await sesi('r001', 'simrad2026'); klik(mwlSebelum.w, mwlSebelum.d.querySelector('#btnMwl')); const dSebelum = await dialogTerbuka(mwlSebelum, 'Modality Worklist');
  const sebelumAda = dSebelum.textContent.includes(o1.accession_no); mwlSebelum.w.close();
  await panggil(rg, 'orders.schedule', { order_id: o1.order_id });
  const mwl = await sesi('r001', 'simrad2026'); klik(mwl.w, mwl.d.querySelector('#btnMwl')); const dMwl = await dialogTerbuka(mwl, 'Modality Worklist');
  const teksMwl = dMwl.textContent;
  ok(!sebelumAda && teksMwl.includes(o1.accession_no) && /CT_ROOM1/.test(teksMwl) && /Aminah\^Siti/.test(teksMwl) && teksMwl.includes('(CITO)'), 'UI30 Modality Worklist: order ORDERED tidak tampil; setelah dijadwalkan tampil dengan atribut DICOM (Nama^Pasien, AE Title)');
  mwl.w.close();
  ok(!(await sesi('d001', 'simrad2026').then(x => { const a = !!x.d.querySelector('#btnMwl'); x.w.close(); return a; })), 'UI31 tombol Modality Worklist hanya untuk radiografer');

  const o2 = await panggil(dok, 'orders.create', { patient_id: 3, procedure_id: prCT7, prioritas: 'REGULER' });
  await panggil(rg, 'orders.schedule', { order_id: o2.order_id }); await panggil(rg, 'orders.start', { order_id: o2.order_id, modality_id: alatCT });
  try {
    let adm = await sesi('adm01', 'simrad2026');
    ok(/PACS \(simulasi\) · ONLINE/.test(adm.d.querySelector('#chipPacs').textContent), 'UI32 chip PACS menampilkan ONLINE');
    klik(adm.w, adm.d.querySelector('#btnPacs')); let kd = await dialogTerbuka(adm, 'Simulasikan PACS mati'); klik(adm.w, btn(kd, 'Matikan PACS simulasi'));
    await adm.until(() => /OFFLINE/.test(adm.d.querySelector('#chipPacs').textContent));
    ok(/OFFLINE/.test(adm.d.querySelector('#chipPacs').textContent), 'UI33 Admin TI dapat mensimulasikan PACS mati; chip berubah menjadi OFFLINE');
    adm.w.close();

    const rg3 = await sesi('r001', 'simrad2026'); let tr3 = rg3.d.querySelector('tr[data-id="' + o2.order_id + '"]');
    klik(rg3.w, btn(tr3, 'Selesai')); let ks = await dialogTerbuka(rg3, 'Selesaikan pemeriksaan'); ks.querySelector('input[type=number]').value = 3; klik(rg3.w, btn(ks, 'Selesaikan'));
    await rg3.until(() => /PACS gagal/.test(rg3.d.querySelector('tr[data-id="' + o2.order_id + '"]').textContent));
    tr3 = rg3.d.querySelector('tr[data-id="' + o2.order_id + '"]');
    ok(/COMPLETED/.test(tr3.textContent) && /PACS gagal/.test(tr3.textContent) && !!btn(tr3, 'Kirim ulang PACS'), 'UI34 PACS mati: pemeriksaan tetap COMPLETED, tag "PACS gagal" dan tombol "Kirim ulang PACS" muncul');
    rg3.w.close();

    adm = await sesi('adm01', 'simrad2026'); klik(adm.w, adm.d.querySelector('#btnPacs')); kd = await dialogTerbuka(adm, 'Nyalakan PACS simulasi'); klik(adm.w, btn(kd, 'Nyalakan'));
    await adm.until(() => /ONLINE/.test(adm.d.querySelector('#chipPacs').textContent)); adm.w.close();

    const rg4 = await sesi('r001', 'simrad2026'); let tr4 = rg4.d.querySelector('tr[data-id="' + o2.order_id + '"]');
    klik(rg4.w, btn(tr4, 'Kirim ulang PACS')); const ku = await dialogTerbuka(rg4, 'Kirim ulang ke PACS'); klik(rg4.w, btn(ku, 'Kirim ulang'));
    await rg4.until(() => /PACS ok/.test(rg4.d.querySelector('tr[data-id="' + o2.order_id + '"]').textContent));
    tr4 = rg4.d.querySelector('tr[data-id="' + o2.order_id + '"]');
    ok(/PACS ok/.test(tr4.textContent) && !btn(tr4, 'Kirim ulang PACS'), 'UI35 setelah PACS online, kirim ulang berhasil: tag "PACS ok" dan tombol kirim ulang hilang');
    klik(rg4.w, tr4); await rg4.until(() => /instance diterima/.test(rg4.d.querySelector('#detail').textContent));
    ok(/3 dari 3 instance diterima \(lengkap\)/.test(rg4.d.querySelector('#detail').textContent), 'UI36 detail order menampilkan "3 dari 3 instance diterima (lengkap)"');
    rg4.w.close();
  } finally { sqlTeks('UPDATE pacs_sim_status SET online = 1'); }
  dok.w.close(); rg.w.close();

  console.log(log.join('\n')); console.log(`\nUI: ${pass} lulus, ${fail} gagal`); process.exit(fail ? 1 : 0);
})().catch(e => { console.log(log.join('\n')); console.error('EXCEPTION', e); process.exit(2); });
