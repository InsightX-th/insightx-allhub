# InsightX Offload (`insightx-offload`)

ปลั๊กอิน WordPress สำหรับอัปโหลด Media Library และไฟล์ในโฟลเดอร์บนเซิร์ฟเวอร์ไปยัง S3-compatible storage พร้อมเสิร์ฟผ่าน Storage/CDN, ย้ายข้อมูลระหว่าง Storage, จัดการไฟล์ และย่อ PDF

เอกสารนี้อ้างอิงโค้ดเวอร์ชัน **0.2.50** — ดูประวัติการเปลี่ยนแปลงทั้งหมดใน [CHANGELOG.md](CHANGELOG.md)

## สารบัญ

- [สิ่งที่เพิ่มเข้ามาล่าสุด](#recent-updates)
- [ฟีเจอร์หลัก](#features)
- [การติดตั้ง](#installation)
- [การตั้งค่าและใช้งาน](#usage)
- [File Manager](#file-manager)
- [รูปภาพและการย่อ PDF](#images-pdf)
- [WP-CLI และงานเบื้องหลัง](#cli)
- [ข้อควรรู้และการแก้ปัญหา](#troubleshooting)
- [การทดสอบสำหรับผู้พัฒนา](#development)
- [License](#license)

<a id="recent-updates"></a>
## สิ่งที่เพิ่มเข้ามาล่าสุด

| เวอร์ชัน | สิ่งที่เพิ่มหรือเปลี่ยน |
| --- | --- |
| **0.2.50** | File Manager จำรายการโฟลเดอร์เพื่อแสดงผลเร็วขึ้น แล้วตรวจข้อมูลล่าสุดเบื้องหลัง พร้อมโหลด tree หลายชั้นพร้อมกัน |
| **0.2.49** | แก้ข้อมูลติดตามไฟล์ที่ถูกงานพร้อมกันเขียนทับ และให้ปุ่มตรวจใน bucket คืนข้อมูลติดตามเมื่อพบไฟล์ขนาดตรงกับ local |
| **0.2.48** | เพิ่มย่อ PDF อัตโนมัติสำหรับไฟล์ที่เพิ่มผ่าน File Manager เข้าโฟลเดอร์ที่เลือก โดยย่อก่อนอัปโหลด |
| **0.2.47** | ย่อ PDF ด้วย Ghostscript เลือก 72 / 150 / 300 dpi และโฟลเดอร์เก็บต้นฉบับได้ อัปโหลดทับ key เดิมสำหรับไฟล์ที่อยู่ใน bucket แล้ว พร้อมคำสั่งย่อ PDF ทีละไฟล์ใน File Manager |
| **0.2.46** | การลบไฟล์ผ่าน File Manager ลบสำเนาบน Storage ที่ปลั๊กอินติดตามไว้ด้วย แสดง URL บน bucket และปุ่มตรวจว่ามีไฟล์จริง พร้อมเมนูคลิกขวาใน tree โฟลเดอร์ |
| **0.2.45** | หน้า File Manager พร้อมสิทธิ์ตาม role/รายบุคคล และอัปโหลดไฟล์ที่เพิ่มนอก Media Library ไปยัง Storage ทันทีเมื่อเปิด Offload Media |
| **0.2.44** | ตรวจโฟลเดอร์บนเซิร์ฟเวอร์ที่เลือกไว้ทุกชั่วโมง เพื่ออัปโหลดไฟล์ใหม่หรือไฟล์ที่เปลี่ยนแปลง |
| **0.2.43** | รวม Media Library และโฟลเดอร์บนเซิร์ฟเวอร์ไว้ในการ์ด Offload media เดียว รวมตัวเลือกการลบไว้ในการ์ดเดียว |
| **0.2.39–0.2.42** | แสดงสถานะ Offload รวมทุกแหล่ง เพิ่มการลบไฟล์ local ที่ตรวจว่ามีบน bucket แล้ว และปรับการอัปโหลดโฟลเดอร์ให้แก้ URL ตอนท้ายงาน |
| **0.2.37–0.2.38** | รูปโปรไฟล์ตัวอักษร รองรับภาษาไทย และแท็บจัดการรูปภาพ |
| **0.2.29–0.2.36** | Migrate โฟลเดอร์นอก Media Library, เลือกโฟลเดอร์จาก tree, Offload โฟลเดอร์บนเซิร์ฟเวอร์ และเติมขนาดรูปที่อัปโหลดไม่ครบ |

<a id="features"></a>
## ฟีเจอร์หลัก

| ฟีเจอร์ | รายละเอียด |
| --- | --- |
| Storage หลายผู้ให้บริการ | Amazon S3, MinIO, Cloudflare R2, DigitalOcean Spaces, Garage, Google Cloud Storage ผ่าน S3-compatible API และ Custom S3 |
| Offload Media | อัปโหลดสื่อใหม่อัตโนมัติ รวมไฟล์หลักและขนาดรูป พร้อมเครื่องมือเติมขนาดที่ขาด |
| Offload โฟลเดอร์บนเซิร์ฟเวอร์ | เลือกโฟลเดอร์นอก Media Library เช่น `download/Specification/` อัปโหลดโดยใช้ path เดิม และตรวจไฟล์ใหม่/แก้ไขทุกชั่วโมง |
| Delivery และ URL Rewrite | เสิร์ฟจาก Storage หรือ CDN, Force HTTPS และเลือกเขียน URL ถาวรลงฐานข้อมูลได้ |
| จัดโครงสร้างไฟล์ | Prefix, ปี/เดือน, Object Version และโฟลเดอร์ตามประเภทเนื้อหา เช่น สินค้า บทความ หมวดหมู่ และแบรนด์ |
| Bulk Jobs | Offload, retry, download, ลบสำเนา local/remote, migrate และจัดการ WooCommerce downloads ทำงานฝั่งเซิร์ฟเวอร์ มี progress, elapsed time และ ETA |
| Migrate | คัดลอกจาก Storage ต้นทางไปปลายทาง รวมโฟลเดอร์ที่ไม่มี attachment ใน WordPress โดยไม่ลบต้นทาง |
| File Manager | ดูและจัดการไฟล์ใต้โฟลเดอร์ WordPress พร้อมกำหนดสิทธิ์และโฟลเดอร์ที่เข้าถึงได้ |
| รูปภาพและ PDF | ปิดการสร้าง thumbnail สำหรับไฟล์ใหม่, รูปโปรไฟล์ตัวอักษร และย่อ PDF ด้วย Ghostscript |
| Media Library | คอลัมน์ Storage/Bucket, ตัวกรองสถานะ รวมรายการที่ขึ้นไม่ครบทุกขนาด และคำสั่ง Offload รายไฟล์/หลายไฟล์ |
| Assets Pull | เปลี่ยน URL ของ CSS/JS ที่ enqueue ให้ผ่าน CDN ซึ่งดึงไฟล์จากเว็บต้นทาง |
| Sync และ Diagnostics | เทียบข้อมูลติดตามกับ bucket เพื่อหาไฟล์หาย, metadata ค้าง และ orphan objects พร้อมทดสอบการเชื่อมต่อ |
| WooCommerce | เครื่องมือจัดการไฟล์สินค้าดาวน์โหลด และลิงก์ดาวน์โหลดแบบ signed URL อายุสั้น |
| WP-CLI | คำสั่งบำรุงรักษาและควบคุมงานชุดเดียวกับหน้า Admin รองรับ external cron |
| ภาษาและอัปเดต | มีคำแปลภาษาไทย และตรวจอัปเดตจาก release assets ของ GitHub |

<a id="installation"></a>
## การติดตั้ง

ข้อกำหนดที่ระบุในปลั๊กอิน: **WordPress 5.8+ และ PHP 7.4+** พร้อมบัญชีและ bucket ของ S3-compatible storage

1. วางโฟลเดอร์ `insightx-offload` ใน `wp-content/plugins/` แล้วเปิดใช้งานปลั๊กอิน
2. หากย้ายมาจาก **InsightX Storage** ให้ปิดปลั๊กอินเดิมก่อน ทั้งสองตัวใช้ options, postmeta, ledger และ cron identifiers ร่วมกัน ข้อมูลเดิมจึงใช้ต่อได้โดยไม่ต้อง Offload ใหม่
3. ไปที่ **InsightX Offload → Connections** ตั้งค่าการเชื่อมต่อ แล้วเลือก provider ปลายทางในแท็บ **Media**
4. เปิด **Offload Media** สำหรับไฟล์ใหม่ และใช้แท็บ **Tools** เพื่ออัปโหลดไฟล์เดิม

การย่อ PDF ต้องมี **Ghostscript (`gs`)** และ PHP ต้องเรียก `exec()` ได้ ส่วน WP-CLI ใช้เฉพาะเมื่อต้องการสั่งงานผ่าน terminal

<a id="usage"></a>
## การตั้งค่าและใช้งาน

### 1. Connections — เชื่อมต่อ Storage

เลือก provider แล้วกรอก Endpoint, Bucket, Region, Access Key ID และ Secret Access Key จากนั้นบันทึกเพื่อทดสอบการเชื่อมต่อ แต่ละ provider มีค่าการเชื่อมต่อที่บันทึกแยกกัน

- ตั้งค่า Path-style และ Public ACL ให้เหมาะกับผู้ให้บริการ
- การบันทึก connection ไม่ได้เปลี่ยน provider ปลายทางโดยอัตโนมัติ ต้องเลือกในแท็บ Media ด้วย
- หากย้ายข้อมูลจากอีก Storage ให้ตั้งค่า connection ต้นทางไว้สำหรับแท็บ Migrate

### 2. Media — จัดเก็บและเสิร์ฟไฟล์

- **Offload Media:** อัปโหลดสื่อใหม่ และเปิดการตรวจโฟลเดอร์บนเซิร์ฟเวอร์ที่เลือกไว้
- **Remove Local Media:** ลบสำเนาสื่อ local หลัง Offload สำเร็จ ตามเงื่อนไขของปลั๊กอิน
- **เขียน URL ถาวรลงฐานข้อมูล:** บันทึก URL ใหม่ในข้อมูลเว็บ งาน Bulk Offload รวบรวมการแก้ URL ไปทำตอนท้ายงาน
- **Deliver Offloaded Media:** เปลี่ยน URL สื่อขณะแสดงผลให้ชี้ไป Storage/CDN
- **CDN Domain / Force HTTPS:** กำหนดโดเมนเสิร์ฟสื่อและใช้ HTTPS
- **โครงสร้าง path:** เลือก prefix, ปี/เดือน, object version หรือโฟลเดอร์ตามประเภทเนื้อหา การเปลี่ยนค่าไม่ได้ย้ายไฟล์ที่ Offload ไปแล้วโดยอัตโนมัติ

### 3. Tools — Offload และจัดการไฟล์จำนวนมาก

ในการ์ด **Offload media** เลือกโฟลเดอร์แล้วกดเริ่มครั้งเดียว:

- `wp-content/uploads/` แทน Media Library ทั้งหมด รวมการเติมขนาดรูปที่ยังขึ้นไม่ครบ
- โฟลเดอร์อื่น เช่น `download/Specification/` อัปโหลดด้วย path เดิมและเก็บต้นฉบับบนเซิร์ฟเวอร์ไว้
- หากขนาดรูปที่ metadata ระบุไม่มีไฟล์จริง ระบบพยายามสร้างจากต้นฉบับ local หรือดาวน์โหลดต้นฉบับจาก bucket มาใช้ชั่วคราว
- ระบบจำไฟล์ในโฟลเดอร์ที่อัปโหลดแล้ว รอบถัดไปตรวจไฟล์ใหม่หรือไฟล์ที่แก้ไข และแก้ลิงก์ตอนท้ายงาน

เมื่อเปิด Offload Media ระบบใช้ WP-Cron ตรวจโฟลเดอร์ที่เลือกทุกชั่วโมง รวมไฟล์ที่เพิ่มผ่าน FTP โดยข้ามรอบเมื่อมีงานอื่นกำลังทำอยู่

เครื่องมืออื่นประกอบด้วย:

- **Retry failed:** ลองใหม่เฉพาะรายการที่ Offload ล้มเหลว
- **Download:** ดาวน์โหลดสื่อกลับจาก Storage
- **ลบไฟล์:** เลือกลบสำเนาสื่อบนเซิร์ฟเวอร์ที่ตรวจแล้วว่ามีบน bucket หรือลบไฟล์บน bucket ที่ปลั๊กอินจัดการ
- **WooCommerce downloads:** ตรวจและปรับการจัดการไฟล์สินค้าดาวน์โหลด
- **Sync:** ตรวจความสอดคล้องระหว่างข้อมูลใน WordPress กับไฟล์จริงบน bucket

การลบสำเนา local ต้องเปิดการเขียน URL ถาวรหรือ Deliver Offloaded Media ไว้ เครื่องมือนี้ไม่ลบไฟล์ที่ไม่มี attachment, โฟลเดอร์ของปลั๊กอินอื่น หรือไฟล์ดาวน์โหลด WooCommerce

งานผ่านระบบ Job ทำต่อบนเซิร์ฟเวอร์ได้เมื่อปิดแท็บ มีปุ่มหยุดพัก/ทำต่อ/ยกเลิก และแสดงความคืบหน้า ทั้งนี้ต้องมี runner ที่ทำงานได้ผ่าน loopback, WP-Cron หรือ external cron

### 4. Migrate — ย้ายระหว่าง Storage

1. ตั้งค่า connection ต้นทางและปลายทาง แล้วเลือกในหน้าที่เกี่ยวข้อง
2. ระบุ **Source Public URL** ให้ตรงกับ URL เดิมที่ใช้ในเว็บ หากเสิร์ฟผ่านโดเมนเฉพาะ/CDN
3. เลือกโฟลเดอร์จาก bucket ต้นทางได้ทั้ง Media Library และโฟลเดอร์อื่น หรือย้ายเฉพาะโฟลเดอร์ที่ไม่มี attachment ก็ได้
4. เริ่มงานเพื่อคัดลอกไฟล์และแก้ URL ไปปลายทาง ระบบไม่ลบไฟล์ต้นทาง

งานแบบเลือกโฟลเดอร์นับไฟล์ก่อนเริ่ม เพื่อแสดงยอดรวมที่คงที่ ใช้ระบบ Job เมื่อต้องการหยุดและทำต่อจากจุดเดิม

### 5. Assets — เสิร์ฟ CSS/JS ผ่าน CDN

เปิด Rewrite Asset URLs และกำหนด CDN Domain ที่ตั้ง origin เป็นเว็บไซต์ WordPress ไฟล์ธีม/ปลั๊กอินยังอยู่บนเซิร์ฟเวอร์ ไม่ได้ถูกอัปโหลดไป bucket และโดเมนนี้ตั้งแยกจาก CDN สำหรับ Media

<a id="file-manager"></a>
## File Manager

เปิด **InsightX Offload → จัดการไฟล์ (File Manager)** เพื่อดู tree โฟลเดอร์และรายการไฟล์บนเซิร์ฟเวอร์

- ค้นหาในโฟลเดอร์ย่อย เรียงรายการ และดูตัวอย่าง/ข้อมูลไฟล์
- อัปโหลดแบบลากวาง สร้างไฟล์หรือโฟลเดอร์ เปลี่ยนชื่อ ย้าย คัดลอก/ตัด/วาง ทำสำเนา ดาวน์โหลด ลบ และล้างโฟลเดอร์
- คลิกขวาที่รายการหรือโฟลเดอร์ใน tree เพื่อเปิดเมนูการทำงาน
- กำหนดสิทธิ์ตาม role หรือรายบุคคล พร้อมจำกัดโฟลเดอร์ที่เข้าถึงได้ กฎรายบุคคลใช้แทนกฎ role ของคนนั้น ผู้มี `manage_options` ได้สิทธิ์ทั้งหมด แต่ยังอยู่ภายใต้ข้อจำกัดด้านไฟล์ของระบบ

เมื่อเปิด Offload Media ไฟล์ที่เพิ่มผ่านหน้านี้นอก Media Library จะขึ้น Storage ปลายทางทันที และเพิ่มโฟลเดอร์เข้ารายการ Offload อัตโนมัติ หากอัปโหลดล้มเหลว รอบตรวจรายชั่วโมงจะลองใหม่

**การลบมีผลถึง Storage:** การลบไฟล์/โฟลเดอร์หรือล้างโฟลเดอร์จะลบสำเนาที่ปลั๊กอินอัปโหลดและติดตามไว้ด้วย การเปลี่ยนชื่อหรือย้ายจะอัปโหลด path ใหม่และลบ key เก่า หน้าข้อมูลของไฟล์นอก Media Library ที่ขึ้นแล้วแสดง URL บน bucket และปุ่มตรวจไฟล์จริง

File Manager ทำงานภายในโฟลเดอร์ WordPress เท่านั้น ส่วนโค้ดเว็บ เช่น `wp-admin`, `wp-includes`, plugins, themes และ `wp-config.php` เป็นแบบอ่านอย่างเดียว ห้ามสร้างไฟล์ที่รันได้ เช่น PHP และการเพิ่ม HTML/SVG ต้องมีสิทธิ์ `unfiltered_html`

<a id="images-pdf"></a>
## รูปภาพและการย่อ PDF

เปิดแท็บ **รูปภาพ/ไฟล์ (Images & Files)**

### รูปภาพ

- **ไม่สร้างรูปขนาดย่อ:** ใช้กับการอัปโหลดใหม่ ไม่ลบ thumbnail เดิม เมื่อไม่มีขนาดย่อย ธีมที่ขอ thumbnail อาจได้ภาพต้นฉบับแทน และไม่มีชุดขนาดสำหรับ `srcset`
- **รูปโปรไฟล์แบบตัวอักษร:** แสดงวงกลมสีพร้อมตัวอักษรแรกของชื่อ รองรับภาษาไทย เสิร์ฟ SVG ผ่าน URL ของเว็บ ไม่โหลด Gravatar หรือสร้างไฟล์ภาพถาวร รูปเดิมยังอยู่และกลับมาเมื่อปิดสวิตช์

### ย่อ PDF

1. ตรวจสถานะ Ghostscript บนการ์ด **ย่อไฟล์ PDF (Shrink PDFs)**
2. เลือกโฟลเดอร์ PDF บนเซิร์ฟเวอร์และความละเอียด **72, 150 หรือ 300 dpi** (ค่าเริ่มต้น 150)
3. เลือกโฟลเดอร์เก็บต้นฉบับ เช่น `pdf-originals/` ให้อยู่นอกโฟลเดอร์ที่กำลังย่อและโฟลเดอร์ที่เลือก Offload ระบบคัดลอกต้นฉบับตาม path เดิมก่อนย่อ หากเว้นว่างจะไม่มีสำเนาสำหรับกู้คืน
4. เริ่มงาน ระบบแทนที่ไฟล์ด้วยชื่อเดิมเฉพาะเมื่อได้ PDF ที่ลดขนาดอย่างน้อย **10%**
5. ไฟล์ที่อยู่บน bucket แล้วจะถูกอัปโหลดทับ **key เดิม** เพื่อใช้ URL เดิมต่อ

เปิด **ย่ออัตโนมัติ** เพื่อย่อ PDF ที่อัปโหลด สร้าง หรือคัดลอกผ่าน File Manager เข้าโฟลเดอร์ที่เลือกก่อนส่งขึ้น Storage หากย่อไม่ได้ ระบบแจ้งเหตุผลและยังส่งไฟล์เดิม

ผู้ดูแลระบบย่อ PDF ทีละไฟล์ได้จากเมนูคลิกขวาใน File Manager ฟีเจอร์นี้ประมวลผลไฟล์บนเซิร์ฟเวอร์ จึงต้องมีไฟล์ local ให้เข้าถึงได้

<a id="cli"></a>
## WP-CLI และงานเบื้องหลัง

รันคำสั่งจากโฟลเดอร์ WordPress หรือกำหนด `--path=/path/to/wordpress` ดูตัวเลือกเพิ่มเติมได้ด้วย `wp help isxm` และ `wp help isxm job`

### คำสั่งบำรุงรักษา

```bash
wp isxm status                       # สรุปสถานะ Media Library
wp isxm offload                      # Offload Media Library ใน foreground
wp isxm offload --batch=100           # ค่าเริ่มต้น 100 attachments ต่อ batch
wp isxm offload --failed-only         # ลองใหม่เฉพาะรายการล้มเหลว
wp isxm download --batch=50           # ดาวน์โหลดสื่อกลับ local
wp isxm backfill --batch=500           # สร้าง/ซิงค์ข้อมูลใน ledger
wp isxm migrate                      # Migrate ใน foreground
wp isxm sync                         # ตรวจสอบแบบ dry-run
```

คำสั่งต่อไปนี้เปลี่ยนข้อมูลหรือลบไฟล์บน Storage:

```bash
wp isxm sync --apply                  # แก้ metadata ที่ไม่ตรงกับ bucket
wp isxm sync --apply --delete-orphans # แก้ metadata และลบ orphan objects
wp isxm remove --batch=50             # ลบสื่อบน bucket ที่ปลั๊กอินติดตาม
```

### จัดการ Connections

provider slug ที่รองรับ: `aws`, `minio`, `garage`, `r2`, `spaces`, `gcs`, `custom` โดยต้องส่งเป็น positional argument

```bash
wp isxm connection list
wp isxm connection get minio
wp isxm connection set minio --endpoint="https://s3.example.com" --bucket="my-bucket" --region="us-east-1" --access-key="KEY" --secret-key="SECRET" --path-style=1
wp isxm connection test minio
```

แทน `KEY` และ `SECRET` ด้วยข้อมูลของคุณ การตั้งค่า connection ผ่าน CLI ไม่ได้เปลี่ยน provider ปลายทางที่เลือกใน Media

### ควบคุม Job ชุดเดียวกับหน้า Admin

คำสั่งตรง เช่น `wp isxm offload` ทำงานใน foreground ส่วน `wp isxm job` ใช้สถานะงานเดียวกับหน้า Admin เหมาะกับการติดตาม หยุดพัก และทำต่อ รวมถึง Offload ตามโฟลเดอร์ที่เลือกในการ์ด

```bash
wp isxm job list
wp isxm job start offload
wp isxm job pause offload
wp isxm job resume offload --watch
wp isxm job cancel offload --yes

# Migrate และแสดง progress ใน terminal จนงานหยุดหรือเสร็จ
wp isxm job start migrate --watch

# เริ่มงานโดยให้ CLI/external cron เป็น runner
wp isxm job start offload --runner=cli
wp isxm job run --once
```

หากใช้ external cron ให้ตั้งรันทุกนาที โดยเปลี่ยน path ให้ตรงกับเว็บไซต์:

```cron
* * * * * cd /path/to/wordpress && wp isxm job run --once > /dev/null 2>&1
```

`job run` ประมวลผลงานที่เริ่มไว้แล้ว ไม่ได้สร้างงาน Offload ใหม่ทุกนาที และไม่ได้แทนการเรียก WP-Cron สำหรับตรวจโฟลเดอร์รายชั่วโมง

<a id="troubleshooting"></a>
## ข้อควรรู้และการแก้ปัญหา

- **งานค้างเมื่อปิดแท็บ:** ตรวจว่า loopback/WP-Cron ทำงานได้ หรือใช้ `wp isxm job run --once` ผ่าน external cron ตรวจสถานะด้วย `wp isxm job list`
- **ไฟล์ที่เพิ่มผ่าน FTP ยังไม่ขึ้น:** เปิด Offload Media และเลือกโฟลเดอร์นั้นในการ์ด Offload media รอบตรวจใช้ WP-Cron ทุกชั่วโมง จึงอาจช้ากว่ากำหนดเมื่อไม่มี request มาเรียก cron
- **เชื่อมต่อได้แต่เปิด URL ไม่ได้:** ตรวจ CDN Domain, เส้นทางและสิทธิ์การอ่านไฟล์ การเชื่อมต่อ S3 สำเร็จไม่ได้หมายความว่า public URL เปิดได้
- **ย่อ PDF ไม่ได้:** ตรวจว่าเซิร์ฟเวอร์มี `gs`, PHP เรียก `exec()` ได้ และมีสิทธิ์เขียนไฟล์/โฟลเดอร์นั้น
- **ย่อ PDF แล้ว URL เดิมยังแสดงไฟล์เก่า:** เนื่องจากใช้ key เดิม CDN อาจยังเก็บไฟล์เก่า ต้อง purge cache ตามระบบ CDN ที่ใช้
- **Secret Key:** ใช้ AES-256-GCM เมื่อ OpenSSL รองรับ โดยอาศัย `wp_salt('auth')` และอ่านข้อมูลเก่าแบบ AES-256-CBC ได้ มี fallback เป็น CBC หาก GCM ใช้ไม่ได้ จึงไม่ควรถือว่าทุกสภาพแวดล้อมเข้ารหัสสำเร็จเสมอ หากเปลี่ยน auth salt อาจต้องกรอก secret ใหม่
- **การถอนการติดตั้ง:** ลบ settings, ข้อมูลติดตาม, ตาราง ledger/migration และ cron ของปลั๊กอิน แต่ไม่ได้ดาวน์โหลดไฟล์จาก bucket กลับให้ก่อน หากต้องการเลิกใช้ Storage ให้ดาวน์โหลดและตรวจ URL ให้เรียบร้อยก่อน

<a id="development"></a>
## การทดสอบสำหรับผู้พัฒนา

รันจากโฟลเดอร์ปลั๊กอิน:

```bash
php tests/run.php
```

ชุดทดสอบใช้ WordPress stubs และ fake S3 บน localhost ไม่ต้องใช้ bucket จริง โดยต้องอนุญาตให้ PHP เปิด process และฟังพอร์ต localhost ได้

<a id="license"></a>
## License

[GPLv3 or later](LICENSE) — © 2026 InsightX
