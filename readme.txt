=== InsightX Offload ===
Contributors: insightx
Tags: offload, s3, minio, cloudflare r2, digitalocean spaces, cdn, migrate, media, wp-cli
Requires at least: 5.8
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.2.52
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Offload media and server folders to S3-compatible storage, with CDN delivery, migration, File Manager, PDF compression and background jobs.

== Description ==

InsightX Offload จัดเก็บสื่อ WordPress และไฟล์ในโฟลเดอร์บนเซิร์ฟเวอร์บน S3-compatible storage พร้อมเสิร์ฟผ่าน Storage/CDN ย้ายไฟล์ระหว่างผู้ให้บริการ จัดการไฟล์ และย่อ PDF จากหลังบ้าน WordPress

รองรับ Amazon S3, MinIO, Cloudflare R2, DigitalOcean Spaces, Garage, Google Cloud Storage ผ่าน S3-compatible API และ Custom S3 พร้อมภาษาไทยและอังกฤษ

**จัดเก็บและเสิร์ฟไฟล์**

* **Offload Media** — อัปโหลดสื่อใหม่อัตโนมัติ รวมไฟล์หลักและขนาดรูป เครื่องมือ Offload ช่วยเติมขนาดที่ขึ้นไม่ครบ และพยายามสร้างขนาดที่ขาดจากต้นฉบับ
* **โฟลเดอร์บนเซิร์ฟเวอร์** — เลือก Media Library และโฟลเดอร์อื่นในการ์ด Offload media เดียว เช่น download/Specification/ ไฟล์นอก Media Library ใช้ path เดิมและเก็บสำเนาบนเซิร์ฟเวอร์ไว้
* **ตรวจไฟล์รายชั่วโมง** — เมื่อเปิด Offload Media ระบบตรวจโฟลเดอร์ที่เลือกผ่าน WP-Cron เพื่ออัปโหลดไฟล์ใหม่หรือไฟล์ที่แก้ไข รวมไฟล์ที่เพิ่มผ่าน FTP
* **Delivery และ URL Rewrite** — กำหนด CDN Domain, Force HTTPS และเลือกเขียน URL ถาวรลงฐานข้อมูล งาน Bulk Offload รวมการแก้ URL ไปทำตอนท้ายงาน
* **โครงสร้างไฟล์** — ตั้ง prefix, ปี/เดือน, Object Version และโฟลเดอร์ตามประเภทเนื้อหา เช่น สินค้า บทความ หมวดหมู่ และแบรนด์
* **Assets Pull** — เสิร์ฟ CSS/JS ที่ enqueue ผ่าน CDN ซึ่งดึงไฟล์จากเว็บไซต์ต้นทาง โดยไม่อัปโหลดไฟล์ธีม/ปลั๊กอินไป bucket

**จัดการไฟล์ รูปภาพ และ PDF**

* **File Manager** — tree โฟลเดอร์ ค้นหาในโฟลเดอร์ย่อย อัปโหลดแบบลากวาง สร้างไฟล์/โฟลเดอร์ เปลี่ยนชื่อ ย้าย คัดลอก ทำสำเนา ดาวน์โหลด ดูตัวอย่าง และลบ พร้อมสิทธิ์ตาม role/รายบุคคลและโฟลเดอร์ที่อนุญาต
* **เชื่อม File Manager กับ Storage** — เมื่อเปิด Offload Media ไฟล์ที่เพิ่มนอก Media Library ขึ้น Storage ทันที การลบจะลบสำเนาบน bucket ที่ปลั๊กอินติดตามไว้ด้วย การย้าย/เปลี่ยนชื่อจะอัปโหลด path ใหม่และลบ key เก่า
* **ตรวจไฟล์บน bucket** — แสดง URL ของไฟล์นอก Media Library ที่ขึ้นแล้วและปุ่มตรวจไฟล์จริง หากพบไฟล์ขนาดตรงกับ local แต่ข้อมูลติดตามหาย ระบบบันทึกข้อมูลติดตามกลับให้
* **ย่อ PDF** — ใช้ Ghostscript เลือก 72 / 150 / 300 dpi และโฟลเดอร์เก็บต้นฉบับ แทนที่ไฟล์ชื่อเดิมเมื่อประหยัดได้อย่างน้อย 10% ไฟล์ที่อยู่บน bucket แล้วอัปโหลดทับ key เดิม
* **ย่อ PDF อัตโนมัติ** — PDF ที่เพิ่มผ่าน File Manager เข้าโฟลเดอร์ที่เลือกจะถูกย่อก่อนอัปโหลดเมื่อเปิดสวิตช์ หากย่อไม่ได้ ระบบแจ้งเหตุผลและยังอัปโหลดไฟล์เดิม
* **รูปภาพและโปรไฟล์** — เลือกไม่สร้าง thumbnail สำหรับการอัปโหลดใหม่ และใช้รูปโปรไฟล์วงกลมตัวอักษรที่รองรับภาษาไทยโดยไม่โหลด Gravatar

**งานเบื้องหลังและเครื่องมือดูแลระบบ**

* **Bulk Jobs** — Offload, Retry failed, Download กลับ, ลบสำเนา local ที่ตรวจว่ามีใน bucket แล้ว, ลบสื่อบน bucket, Migrate และจัดการ WooCommerce downloads พร้อมหยุดพัก/ทำต่อ/ยกเลิก ความคืบหน้า เวลาที่ใช้ และ ETA
* **Migrate** — คัดลอกระหว่าง Storage รวมโฟลเดอร์ที่ไม่มี attachment ใน Media Library แล้วแก้ URL ไปปลายทาง โดยไม่ลบไฟล์ต้นทาง
* **Media Library** — คอลัมน์ Storage/Bucket ตัวกรองสถานะ รวมรูปที่ขึ้นไม่ครบทุกขนาด และคำสั่ง Offload รายไฟล์/หลายไฟล์
* **Sync และ Diagnostics** — เทียบข้อมูลติดตามกับ bucket เพื่อหา metadata ค้าง ไฟล์หาย และ orphan objects พร้อมทดสอบ connection
* **WooCommerce** — จัดการไฟล์สินค้าดาวน์โหลดและเสิร์ฟผ่าน signed URL อายุสั้น
* **WP-CLI** — ตรวจสถานะ สั่งงาน และควบคุม Job ชุดเดียวกับหน้า Admin รองรับ external cron

Secret Key ใช้ AES-256-GCM เมื่อ OpenSSL รองรับ และยังอ่านข้อมูลเดิมแบบ AES-256-CBC ได้

== Installation ==

= ติดตั้งครั้งแรก =

1. ดาวน์โหลด release ZIP ของ InsightX Offload จาก https://github.com/InsightX-th/insightx-offload/releases หรือวางโฟลเดอร์ insightx-offload ใน wp-content/plugins/
2. ติดตั้งและเปิดใช้งานผ่านหน้า ปลั๊กอิน หากใช้ InsightX Storage อยู่ ให้ปิดตัวเดิมก่อน เพราะทั้งสองปลั๊กอินใช้ข้อมูลและ hooks ร่วมกัน
3. ไปที่ InsightX Offload → Connections เลือก provider แล้วกรอก Endpoint, Bucket, Region, Access Key และ Secret Key บันทึกเพื่อทดสอบการเชื่อมต่อ
4. ไปที่ Media เลือก provider ปลายทาง เปิด Offload Media และตั้ง Delivery/CDN ตามต้องการ
5. ไปที่ Tools → Offload media เลือก Media Library หรือโฟลเดอร์บนเซิร์ฟเวอร์ แล้วเริ่มอัปโหลดไฟล์เดิม
6. หากต้องการใช้ File Manager ให้เปิดเมนู จัดการไฟล์ และกำหนดสิทธิ์สำหรับผู้ใช้ที่ต้องการให้เข้าถึง
7. หากต้องการย่อ PDF ให้ไปที่ รูปภาพ/ไฟล์ ตรวจสถานะ Ghostscript เลือกโฟลเดอร์ ความละเอียด และโฟลเดอร์เก็บต้นฉบับก่อนเริ่มงาน

= ความต้องการเพิ่มเติม =

* PHP 7.4 ขึ้นไป และ connection ไปยัง bucket ของ S3-compatible storage
* Ghostscript (gs) และ PHP exec() สำหรับย่อ PDF
* Loopback/WP-Cron ที่ทำงานได้ หรือ external CLI runner สำหรับงานเบื้องหลัง
* WP-CLI เฉพาะเมื่อต้องการสั่งงานผ่าน terminal

= อัปเดตหรือย้ายมาจาก InsightX Storage =

ปลั๊กอินตรวจอัปเดตจาก GitHub Releases ผ่านหน้าปลั๊กอินของ WordPress หากย้ายมาจาก InsightX Storage ให้ปิดตัวเดิมก่อน ข้อมูลเดิมใช้ต่อได้โดยไม่ต้อง Offload ใหม่ ห้ามเปิดทั้งสองตัวพร้อมกัน

== Frequently Asked Questions ==

= ต่างจากปลั๊กอินสำรองข้อมูลอย่างไร? =

InsightX Offload จัดเก็บและเสิร์ฟไฟล์สื่อ/โฟลเดอร์บน Storage ไม่ได้สร้างแพ็กเกจสำรองทั้งเว็บไซต์หรือฐานข้อมูลแบบ InsightX Backup

= อัปโหลดไฟล์นอก Media Library ได้ไหม? =

ได้ เลือกโฟลเดอร์ในการ์ด Offload media เช่น download/Specification/ ระบบใช้ path เดิมและเก็บไฟล์ local ไว้ เมื่อเปิด Offload Media จะตรวจไฟล์ใหม่/แก้ไขทุกชั่วโมงผ่าน WP-Cron ส่วนไฟล์ที่เพิ่มผ่าน File Manager นอก Media Library จะถูกอัปโหลดทันที

= ลบไฟล์ใน File Manager แล้วไฟล์บน bucket หายด้วยไหม? =

หายด้วยสำหรับสำเนาที่ปลั๊กอินอัปโหลดและติดตามไว้ ทั้งการลบไฟล์ ลบโฟลเดอร์ และล้างโฟลเดอร์ การย้ายหรือเปลี่ยนชื่อจะอัปโหลดชื่อใหม่แล้วลบ key เก่า ตรวจปลายทางในหน้าต่างยืนยันก่อนทำรายการ

= กำหนดสิทธิ์ File Manager ได้อย่างไร? =

กำหนดตาม role หรือรายบุคคล พร้อมจำกัดโฟลเดอร์ที่เข้าถึงได้ กฎรายบุคคลใช้แทนกฎ role ของผู้ใช้นั้น ผู้มี manage_options ได้สิทธิ์ทั้งหมด แต่ยังอยู่ใต้ข้อจำกัดไฟล์ของระบบ: ทำงานภายใน WordPress โค้ดระบบเป็นแบบอ่านอย่างเดียว และห้ามสร้างไฟล์ที่รันได้ เช่น PHP ส่วน HTML/SVG ต้องมี unfiltered_html

= ย่อ PDF ต้องมีอะไร และเก็บต้นฉบับได้ไหม? =

ต้องมี Ghostscript (gs), PHP exec() และไฟล์ local ที่เขียนได้ เลือกความละเอียด 72 / 150 / 300 dpi และโฟลเดอร์เก็บต้นฉบับนอกโฟลเดอร์ที่ย่อ/Offload ระบบแทนไฟล์เมื่อขนาดลดอย่างน้อย 10% หากไม่เลือกที่เก็บต้นฉบับจะไม่มีสำเนาสำหรับกู้คืน ไฟล์บน bucket ใช้ key เดิม จึงอาจต้อง purge CDN cache เพื่อเห็นไฟล์ใหม่

= ย่อ PDF อัตโนมัติทำงานกับไฟล์ใด? =

ทำงานเมื่อเปิด ย่ออัตโนมัติ และเพิ่ม PDF ผ่าน File Manager เข้าโฟลเดอร์ที่เลือก เช่น อัปโหลด สร้าง หรือคัดลอกไฟล์ ระบบย่อก่อนส่งขึ้น Storage ถ้าย่อไม่สำเร็จยังส่งไฟล์เดิมและแจ้งเหตุผล ไม่ใช่การเฝ้าย่อ PDF ทุกไฟล์จากทุกช่องทาง

= Remove Local Media กับเครื่องมือลบ local ต่างกันอย่างไร? =

Remove Local Media ใช้กับสื่อหลัง Offload สำเร็จ ส่วนเครื่องมือใน Tools ใช้ลบสำเนา Media Library เดิมที่ตรวจแล้วว่ามีบน bucket โดยต้องเปิดการเขียน URL ถาวรหรือ Deliver Offloaded Media ไว้ เครื่องมือนี้ไม่ลบไฟล์ที่ไม่มี attachment หรือไฟล์ดาวน์โหลด WooCommerce

= Migrate ลบไฟล์จากต้นทางหรือไม่? =

ไม่ ระบบคัดลอกไป Storage ปลายทางและแก้ URL รองรับ Media Library และโฟลเดอร์อื่นจาก bucket ต้นทาง หากเว็บใช้ CDN เดิม ให้ตั้ง Source Public URL ให้ตรงกับลิงก์เดิมในเว็บ

= ไฟล์ CSS/JS ถูกอัปโหลดขึ้น bucket ไหม? =

ไม่ ไฟล์ธีมและปลั๊กอินยังอยู่บนเซิร์ฟเวอร์ Assets Pull เปลี่ยน URL ให้ผ่าน CDN ที่ตั้ง origin เป็นเว็บไซต์ ต้องกำหนด CDN สำหรับ Assets แยกจากโดเมนเสิร์ฟ Media

= งาน Offload จำนวนมากใช้เวลานานเท่าไร? =

ขึ้นกับจำนวน/ขนาดไฟล์ เครือข่าย และเซิร์ฟเวอร์ Bulk Offload รวมการเขียน URL ถาวรไปทำตอนท้ายงานแทนการสแกนฐานข้อมูลทุก batch และแสดงความคืบหน้าพร้อม ETA จึงไม่มีระยะเวลาตายตัวสำหรับจำนวนไฟล์หนึ่งๆ

= ปิดแท็บแล้วงานทำต่อไหม? =

งานที่เริ่มผ่านระบบ Job ทำต่อฝั่งเซิร์ฟเวอร์ได้ แต่ต้องมี loopback, WP-Cron หรือ CLI runner ทำงาน เว็บไซต์ที่ไม่มีทราฟฟิกอาจเรียก WP-Cron ช้ากว่ากำหนด รวมถึงรอบตรวจโฟลเดอร์รายชั่วโมง

= ใช้ WP-CLI หรือ external cron อย่างไร? =

รันจากโฟลเดอร์ WordPress หรือระบุ --path ให้ตรงกับเว็บไซต์:

* `wp isxm status` — สรุปสถานะ Media Library
* `wp isxm connection test minio` — ทดสอบ provider ที่บันทึกไว้
* `wp isxm job start offload` — เริ่มงานตามการ์ด Offload ใน Admin
* `wp isxm job list` — ดูสถานะงาน
* `wp isxm job pause offload` — หยุดพัก
* `wp isxm job resume offload --watch` — ทำต่อและแสดง progress
* `wp isxm job start migrate --watch` — ย้ายข้อมูลผ่านระบบ Job
* `wp isxm job start offload --runner=cli` — เริ่มงานให้ CLI เป็น runner
* `wp isxm job run --once` — ประมวลผลงานที่เริ่มไว้แล้ว เหมาะสำหรับ external cron

คำสั่งตรงอย่าง wp isxm offload ทำงานใน foreground ส่วน wp isxm job ใช้สถานะงานเดียวกับ Admin คำสั่ง job run ไม่สร้างงานใหม่และไม่ได้แทนการเรียก WP-Cron เพื่อตรวจโฟลเดอร์รายชั่วโมง

= ปิดการสร้าง thumbnail แล้วรูปเก่าหายไหม? =

ไม่ ตัวเลือกนี้ใช้กับการอัปโหลดใหม่ ไม่ลบขนาดรูปเดิม หากไม่มีขนาดย่อย ธีมที่ขอ thumbnail อาจได้ภาพต้นฉบับแทน และไม่มีชุดขนาดสำหรับ srcset

= รูปโปรไฟล์ตัวอักษรลบรูปเดิมไหม? =

ไม่ ระบบแสดงวงกลมสีพร้อมอักษรแรกของชื่อผ่าน URL ของเว็บ โดยไม่โหลด Gravatar รูปเดิมยังอยู่และกลับมาเมื่อปิดสวิตช์
