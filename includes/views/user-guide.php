<?php
/** Thai user guide for the standalone admin help page. */
if ( ! defined( 'ABSPATH' ) ) exit;

$guide_topics = [
    'start' => 'เริ่มต้นใช้งาน',
    'connections' => 'เชื่อมต่อ Storage',
    'media' => 'ตั้งค่าสื่อและ CDN',
    'tools' => 'Offload ไฟล์เดิมและเครื่องมือ',
    'migrate' => 'ย้ายระหว่าง Storage',
    'files' => 'จัดการไฟล์',
    'images' => 'รูปภาพและย่อ PDF',
    'assets' => 'เสิร์ฟ CSS / JS ผ่าน CDN',
    'backup' => 'สำรองและกู้คืนเว็บ',
    'troubleshooting' => 'แก้ปัญหาที่พบบ่อย',
];

// Screenshots of the installed plugin UI, with connection values redacted.
$guide_figure = static function ( $file, $alt, $caption, $width, $height ) {
    $url = ISXM_PLUGIN_URL . 'assets/images/guide/' . $file;
    ?>
    <figure class="isxs-guide-figure">
        <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( 'เปิดภาพขนาดเต็มในแท็บใหม่: ' . $caption ); ?>">
            <img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" width="<?php echo esc_attr( $width ); ?>" height="<?php echo esc_attr( $height ); ?>" loading="lazy" decoding="async">
        </a>
        <figcaption><?php echo esc_html( $caption ); ?><span>คลิกภาพเพื่อดูขนาดเต็มในแท็บใหม่</span></figcaption>
    </figure>
    <?php
};
?>
<div class="isxs-wrap isxs-guide" lang="th">
    <header class="isxs-header isxs-guide-header" id="isxs-guide-top">
        <div class="isxs-header-brand">
            <div class="isxs-logo" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6v15M3 3h5a4 4 0 0 1 4 3 4 4 0 0 1 4-3h5v15h-5a4 4 0 0 0-4 3 4 4 0 0 0-4-3H3z"/></svg>
            </div>
            <div>
                <h1>คู่มือการใช้งาน InsightX Offload</h1>
                <p class="isxs-tagline">ตั้งค่าพื้นที่เก็บไฟล์บนคลาวด์ ย้ายสื่อ จัดการไฟล์ และสำรองข้อมูลเว็บไซต์</p>
            </div>
        </div>
        <a class="isxs-btn isxs-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ISXM_Admin::PAGE_SLUG ) ); ?>">เปิดหน้าตั้งค่า Offload <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></a>
    </header>

    <div class="isxs-guide-layout">
        <nav class="isxs-guide-toc" aria-label="สารบัญคู่มือ">
            <h2 id="isxs-guide-topics-label">หัวข้อในคู่มือ</h2>
            <select class="isxs-guide-topic-select" aria-labelledby="isxs-guide-topics-label">
                <?php foreach ( $guide_topics as $guide_id => $guide_title ) : ?>
                    <option value="isxs-guide-<?php echo esc_attr( $guide_id ); ?>"><?php echo esc_html( $guide_title ); ?></option>
                <?php endforeach; ?>
            </select>
            <ul>
                <?php foreach ( $guide_topics as $guide_id => $guide_title ) : ?>
                    <li><a href="#isxs-guide-<?php echo esc_attr( $guide_id ); ?>"><?php echo esc_html( $guide_title ); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <article class="isxs-guide-content">
            <section id="isxs-guide-start" aria-labelledby="isxs-guide-start-title">
                <h2 id="isxs-guide-start-title">เริ่มต้นใช้งาน</h2>
                <p>Offload คือการคัดลอกสื่อจากเว็บไซต์ไปเก็บบน Storage ส่วน CDN หรือโดเมนเสิร์ฟไฟล์ใช้เปิดไฟล์เหล่านั้นให้ผู้เข้าชม การเชื่อมต่อ Storage สำเร็จจึงยังต้องตรวจว่า URL ของไฟล์เปิดได้ด้วย</p>
                <?php $guide_figure( 'navigation-real.png', 'แถบเมนูจริงของ InsightX Offload มีสื่อ รูปภาพ/ไฟล์ ทรัพยากร เครื่องมือ ย้ายข้อมูล การเชื่อมต่อ และช่วยเหลือ', 'หน้าจอจริง: แถบเมนูภายใน Offload', 1440, 135 ); ?>
                <p>เตรียมบัญชี Storage, Bucket, Endpoint, Region, Access Key และ Secret Key ของคุณ ปลั๊กอินรองรับ AWS S3, MinIO, Garage, Cloudflare R2, DigitalOcean Spaces, Google Cloud Storage และผู้ให้บริการ S3-compatible อื่น</p>
                <ol>
                    <li>เปิด <strong>InsightX Offload → Offload → การเชื่อมต่อ (Connections)</strong> กรอกข้อมูลผู้ให้บริการ แล้วกด <strong>บันทึก (Save)</strong> เพื่อบันทึกและทดสอบ</li>
                    <li>ไปแท็บ <strong>สื่อ (Media)</strong> เลือกผู้ให้บริการที่เชื่อมต่อสำเร็จเป็นปลายทาง</li>
                    <li>เปิด <strong>ส่งสื่อขึ้น Storage อัตโนมัติ (Offload Media)</strong> เพื่ออัปโหลดสื่อใหม่ไป Storage และเปิด <strong>แสดงสื่อจาก Storage/CDN</strong> หากต้องการให้เว็บใช้ URL บน Storage/CDN</li>
                    <li>ทดลองอัปโหลดรูปหนึ่งรูปใน Media Library แล้วตรวจรูปบนหน้าเว็บและ URL ของไฟล์</li>
                    <li>ไปแท็บ <strong>เครื่องมือ (Tools)</strong> เลือกโฟลเดอร์ในการ์ด <strong>Offload media</strong> แล้วกด <strong>เริ่ม Offload (Start Offload)</strong> เพื่อส่งไฟล์เดิมขึ้น Storage</li>
                </ol>
                <p class="isxs-guide-note"><strong>ก่อนลบสำเนาในเครื่อง:</strong> เก็บข้อมูลสำรองและตรวจว่าไฟล์จาก Storage เปิดได้จริง ควรเริ่มโดยปิด Remove Local Media ไว้ก่อน โดยเฉพาะเว็บไซต์ที่ใช้ปลั๊กอินแก้ไขภาพซึ่งต้องใช้ไฟล์ต้นฉบับบนเซิร์ฟเวอร์</p>
                <p>ในหน้า Offload สวิตช์และค่าตั้งค่าทั่วไปบันทึกอัตโนมัติ ส่วนข้อมูลแต่ละการเชื่อมต่อต้องกดปุ่ม <strong>บันทึก</strong> ในการ์ดนั้น</p>
            </section>

            <section id="isxs-guide-connections" aria-labelledby="isxs-guide-connections-title">
                <h2 id="isxs-guide-connections-title">เชื่อมต่อ Storage</h2>
                <p>เปิดแท็บ <strong>การเชื่อมต่อ (Connections)</strong> แล้วกรอกข้อมูลในการ์ดผู้ให้บริการที่ต้องการ แต่ละผู้ให้บริการเก็บข้อมูลการเชื่อมต่อแยกกัน</p>
                <?php $guide_figure( 'connections-real.png', 'หน้าการเชื่อมต่อ Garage พร้อมช่อง Endpoint, Region, Bucket, Access Key และ Secret Key และปุ่มบันทึก โดยล้างค่าการเชื่อมต่อออกจากภาพ', 'หน้าจอจริง: การเชื่อมต่อ Storage (ปิดบังค่าการเชื่อมต่อแล้ว)', 720, 696 ); ?>
                <dl>
                    <dt>Endpoint URL</dt><dd>ที่อยู่ API ของ Storage เช่น <code>https://s3.example.com</code> ใช้ค่าจากผู้ให้บริการ ไม่ใช่ URL ของเว็บไซต์หรือโดเมน CDN</dd>
                    <dt>Region และ Bucket</dt><dd>ชื่อภูมิภาคและชื่อ Bucket ที่สร้างไว้ กรอกชื่อ Bucket โดยไม่ใส่ URL หรือชื่อโฟลเดอร์ต่อท้าย</dd>
                    <dt>Access Key และ Secret Key</dt><dd>ข้อมูลรับรองสำหรับเชื่อมต่อ Storage จุดในช่อง Secret Key แทนค่าที่บันทึกไว้ ไม่ใช่รหัสจริง และไม่ควรส่งรหัสนี้พร้อมภาพหน้าจอแจ้งปัญหา</dd>
                    <dt>Path-style URL</dt><dd>เปิดสำหรับ MinIO / Garage ตามการตั้งค่าของเซิร์ฟเวอร์ ส่วน AWS S3 โดยทั่วไปใช้ virtual-hosted URL</dd>
                    <dt>Send ACL public-read</dt><dd>เปิดเฉพาะเมื่อ Bucket รองรับ ACL หากใช้ policy ของ Bucket หรือผู้ให้บริการไม่รองรับ ACL ให้ปิดตัวเลือกนี้</dd>
                </dl>
                <p>กด <strong>บันทึก (Save)</strong> และรอผลทดสอบ เมื่อเชื่อมต่อสำเร็จจึงเลือกผู้ให้บริการนั้นในแท็บสื่อหรือย้ายข้อมูลได้ หากไม่สำเร็จให้ตรวจข้อความผิดพลาด รวมถึง Endpoint, Region, ชื่อ Bucket และสิทธิ์ของ Key</p>
            </section>

            <section id="isxs-guide-media" aria-labelledby="isxs-guide-media-title">
                <h2 id="isxs-guide-media-title">ตั้งค่าสื่อและ CDN</h2>
                <p>เปิดแท็บ <strong>สื่อ (Media)</strong> แล้วเลือกปลายทางในการ์ดตั้งค่าการจัดเก็บไฟล์</p>
                <?php $guide_figure( 'media-real.png', 'การ์ดตั้งค่าจัดเก็บไฟล์จริง แสดง Offload Media, Remove Local Media, การเขียน URL ถาวร และตัวเลือกเส้นทางไฟล์', 'หน้าจอจริง: ตั้งค่าจัดเก็บไฟล์ — สถานะสวิตช์เป็นค่าของเว็บที่จับภาพ ให้เลือกตามการใช้งานของคุณ', 711, 603 ); ?>
                <dl>
                    <dt>ส่งสื่อขึ้น Storage อัตโนมัติ (Offload Media)</dt><dd>คัดลอกสื่อใหม่ขึ้น Storage หลังอัปโหลด และตรวจโฟลเดอร์อื่นที่เลือกไว้ตามรอบรายชั่วโมงผ่าน WP-Cron</dd>
                    <dt>แสดงสื่อจาก Storage/CDN</dt><dd>เปลี่ยน URL สื่อขณะแสดงผลให้ใช้ Storage หรือ CDN</dd>
                    <dt>บันทึก URL ถาวรในฐานข้อมูล</dt><dd>แทนที่ URL สื่อเดิมที่บันทึกในข้อมูลเว็บไซต์ งาน Offload และ Migrate แบบชุดจะทำขั้นตอนนี้ช่วงท้าย จึงควรรอจนงานเสร็จ</dd>
                    <dt>ลบสำเนาบนเซิร์ฟเวอร์ (Remove Local Media)</dt><dd>ลบสำเนาสื่อบนเซิร์ฟเวอร์หลังยืนยันว่ามีไฟล์บน Bucket แล้ว โดยต้องเปิดการเขียน URL ถาวรหรือ Deliver Offloaded Media ด้วย</dd>
                    <dt>Custom Delivery Domain (CDN) และ Force HTTPS</dt><dd>ใส่โดเมน CDN ที่เชื่อมกับ Bucket แล้ว เช่น <code>cdn.example.com</code> หากเว้นว่างจะใช้ URL ของ Bucket เปิด Force HTTPS เมื่อโดเมนรองรับ HTTPS</dd>
                    <dt>Prefix / ปีและเดือน / Object Version / Folder Structure</dt><dd>ใช้กำหนดเส้นทางไฟล์บน Bucket ดูตัวอย่าง URL หลังปรับค่า การเปลี่ยนโครงสร้างไม่ได้ย้ายไฟล์ที่ Offload ไปแล้วโดยอัตโนมัติ</dd>
                </dl>
                <p class="isxs-guide-note">หากใช้ Bucket ร่วมกันหลายเว็บ ควรใช้ Prefix แยกแต่ละเว็บ โดยเฉพาะก่อนใช้เครื่องมือลบไฟล์ที่ไม่มีข้อมูลอ้างอิง (orphan objects)</p>
            </section>

            <section id="isxs-guide-tools" aria-labelledby="isxs-guide-tools-title">
                <h2 id="isxs-guide-tools-title">Offload ไฟล์เดิมและเครื่องมือ</h2>
                <?php $guide_figure( 'offload-real.png', 'การ์ด Offload media จริง มีช่องเลือกโฟลเดอร์ ปุ่มเพิ่มโฟลเดอร์ และปุ่มเริ่ม Offload', 'หน้าจอจริง: การ์ด Offload media ในแท็บเครื่องมือ', 720, 256 ); ?>
                <ol>
                    <li>เปิดแท็บ <strong>เครื่องมือ (Tools)</strong> แล้วเลือกโฟลเดอร์ในการ์ด <strong>Offload media</strong></li>
                    <li>เลือกโฟลเดอร์ uploads ของเว็บไซต์สำหรับ Media Library หรือเพิ่มโฟลเดอร์อื่น เช่น <code>download/Specification/</code></li>
                    <li>กด <strong>Start Offload</strong> แล้วดูความคืบหน้า ระบบจะส่งไฟล์และแก้ URL ช่วงท้ายงาน</li>
                    <li>หากมีรายการล้มเหลว เปิดดูรายการในหน้าสื่อ แล้วใช้ <strong>Retry failed</strong> หลังแก้สาเหตุ</li>
                </ol>
                <p>ไฟล์ในโฟลเดอร์อื่นที่ไม่ใช่ Media Library ใช้ path เดิมและเก็บต้นฉบับบนเซิร์ฟเวอร์ไว้ รอบถัดไปจะตรวจไฟล์ใหม่หรือไฟล์ที่เปลี่ยนแปลง เมื่อเปิด Offload Media ระบบตรวจโฟลเดอร์ที่เลือกทุกชั่วโมง แต่ WP-Cron อาจทำงานช้ากว่ากำหนดเมื่อเว็บไซต์ไม่มีการเข้าชม</p>
                <h3>หยุดพัก ทำต่อ และยกเลิก</h3>
                <ul>
                    <li><strong>หยุดพัก / ทำต่อ (Stop / Resume):</strong> หยุดพักและทำต่อจากจุดที่บันทึกไว้</li>
                    <li><strong>ยกเลิก (Cancel):</strong> ยกเลิกงานและลบจุดที่บันทึกไว้ งานที่ทำไปแล้วคงอยู่ รอบใหม่จะเริ่มสแกนตั้งแต่ต้น</li>
                    <li>งานทำต่อบนเซิร์ฟเวอร์ได้เมื่อมี loopback, WP-Cron หรือ external runner ที่ใช้งานได้ หากขึ้นข้อความให้เปิดหน้านี้ไว้ ให้รอจนงานเสร็จ</li>
                </ul>
                <h3>เลือกเครื่องมือให้ตรงกับสิ่งที่ต้องการ</h3>
                <dl>
                    <dt>Download</dt><dd>ดาวน์โหลดสื่อกลับมาไว้บนเซิร์ฟเวอร์ เหมาะสำหรับเตรียมเลิกใช้ Storage หรือเมื่อต้องการไฟล์ local กลับมา ตรวจไฟล์และ URL ก่อนปิดปลั๊กอิน</dd>
                    <dt>ลบสำเนาบนเซิร์ฟเวอร์</dt><dd>ลบเฉพาะสื่อที่ตรวจยืนยันว่ามีบน Bucket และผ่านเงื่อนไข ไม่ใช่คำสั่งล้างโฟลเดอร์ทั้งหมด</dd>
                    <dt>ลบไฟล์บน Bucket</dt><dd>ลบไฟล์ที่ปลั๊กอินจัดการบน Storage โดยดาวน์โหลดสื่อที่ขาดจากเซิร์ฟเวอร์กลับก่อน ควรมีข้อมูลสำรองก่อนเริ่ม</dd>
                    <dt>ตรวจสอบไฟล์กับ Bucket (Sync with bucket)</dt><dd>ตรวจข้อมูลติดตามเทียบกับไฟล์จริง อ่านผลก่อนใช้ “ล้างข้อมูลติดตามที่ไม่ตรง” หรือ “ลบไฟล์ที่ไม่มีสื่ออ้างอิง” การลบ orphan อาจกระทบไฟล์ของเว็บอื่นที่ใช้ Prefix เดียวกัน</dd>
                    <dt>WooCommerce downloads</dt><dd>ใช้เครื่องมือสำหรับไฟล์สินค้าดาวน์โหลด แล้วทดสอบลิงก์ดาวน์โหลดจากฝั่งผู้ซื้อหลังปรับค่า</dd>
                </dl>
            </section>

            <section id="isxs-guide-migrate" aria-labelledby="isxs-guide-migrate-title">
                <h2 id="isxs-guide-migrate-title">ย้ายระหว่าง Storage</h2>
                <p>ใช้แท็บ <strong>ย้ายข้อมูล (Migrate)</strong> เมื่อต้องการคัดลอกไฟล์จาก Storage เดิมไปยังปลายทางใหม่</p>
                <ol>
                    <li>ตั้งค่าและทดสอบการเชื่อมต่อทั้งต้นทางและปลายทางใน Connections</li>
                    <li>เลือกต้นทางและปลายทาง ตรวจชื่อ Bucket ให้ตรงกับที่ต้องการ</li>
                    <li>กรอก <strong>Source Public URL</strong> ให้ตรงกับ URL เดิมที่ใช้บนเว็บไซต์ โดยเฉพาะเมื่อใช้ CDN หรือโดเมนเฉพาะ</li>
                    <li>เลือกโฟลเดอร์ต้นทางที่ต้องการย้าย แล้วเริ่มงาน ระบบอาจนับไฟล์ก่อนแสดงความคืบหน้าการคัดลอก</li>
                    <li>รอให้คัดลอกและแก้ URL เสร็จ จากนั้นตรวจหน้าเว็บ รูป และไฟล์ดาวน์โหลด</li>
                </ol>
                <p>การย้ายไม่ลบไฟล์ต้นทาง เก็บต้นทางไว้จนตรวจปลายทางครบแล้ว และใช้ Stop / Resume เมื่อต้องการพักงาน</p>
            </section>

            <section id="isxs-guide-files" aria-labelledby="isxs-guide-files-title">
                <h2 id="isxs-guide-files-title">จัดการไฟล์</h2>
                <p>เปิด <strong>InsightX Offload → จัดการไฟล์ (File Manager)</strong> เพื่อจัดการไฟล์บนเซิร์ฟเวอร์ WordPress</p>
                <ul>
                    <li>เลือกโฟลเดอร์จากแถบด้านข้าง ค้นหา เรียงรายการ และดูตัวอย่างหรือข้อมูลไฟล์</li>
                    <li>ลากไฟล์มาวางเพื่ออัปโหลด หรือคลิกขวาที่ไฟล์หรือโฟลเดอร์เพื่อสร้าง เปลี่ยนชื่อ ย้าย คัดลอก ดาวน์โหลด หรือลบตามสิทธิ์ที่มี</li>
                    <li>ติ๊กหลายรายการเพื่อทำงานพร้อมกัน รอให้ความคืบหน้าจบก่อนออกจากหน้า เพราะรายการที่ยังไม่เริ่มจะไม่ทำต่อเมื่อออกจากหน้า</li>
                    <li>ผู้ดูแลกำหนดสิทธิ์และโฟลเดอร์ที่แต่ละ role เข้าถึงได้ ผู้ใช้อื่นอาจเห็นตัวเลือกไม่เท่าผู้ดูแล</li>
                </ul>
                <p>เมื่อเปิด Offload Media ไฟล์นอก Media Library ที่เพิ่มผ่าน File Manager จะถูกส่งขึ้น Storage ผู้ดูแลเพิ่มโฟลเดอร์เข้า Offload ได้อัตโนมัติตามข้อจำกัดของระบบ ส่วน role อื่นส่งขึ้นได้เฉพาะโฟลเดอร์ที่เลือกไว้แล้ว</p>
                <p class="isxs-guide-note"><strong>การลบมีผลถึง Storage:</strong> ลบไฟล์ ลบโฟลเดอร์ หรือล้างโฟลเดอร์ จะลบสำเนาบน Storage ที่ปลั๊กอินติดตามด้วย การย้ายหรือเปลี่ยนชื่อจะเปลี่ยน path และ key ของไฟล์</p>
                <p>ไฟล์สำคัญ เช่น <code>wp-config.php</code>, ไฟล์ที่ขึ้นต้นด้วยจุด, ฐานข้อมูล, log, key และโฟลเดอร์สำรองข้อมูลถูกซ่อนไว้ ส่วนโฟลเดอร์โค้ด WordPress, plugins และ themes เป็นแบบอ่านอย่างเดียว</p>
            </section>

            <section id="isxs-guide-images" aria-labelledby="isxs-guide-images-title">
                <h2 id="isxs-guide-images-title">รูปภาพและย่อ PDF</h2>
                <p>เปิดแท็บ <strong>รูปภาพ/ไฟล์ (Images &amp; Files)</strong></p>
                <ul>
                    <li><strong>ไม่สร้างรูปขนาดย่อย:</strong> มีผลกับการอัปโหลดใหม่ ไม่ลบ thumbnail เดิม แต่จะไม่มีชุดขนาดรูปสำหรับ responsive images (<code>srcset</code>)</li>
                    <li><strong>รูปโปรไฟล์แบบตัวอักษร:</strong> แสดงตัวอักษรแรกของชื่อแทนรูป โดยไม่โหลด Gravatar รูปเดิมยังอยู่และกลับมาเมื่อปิดตัวเลือก</li>
                </ul>
                <h3>ย่อไฟล์ PDF</h3>
                <ol>
                    <li>ตรวจว่าการ์ด <strong>ลดขนาดไฟล์ PDF (Shrink PDFs)</strong> พบ Ghostscript และไฟล์ PDF ยังมีอยู่บนเซิร์ฟเวอร์</li>
                    <li>เลือกโฟลเดอร์และความละเอียด 72, 150 หรือ 300 dpi ค่าเริ่มต้นคือ 150 dpi</li>
                    <li>ระบุโฟลเดอร์เก็บต้นฉบับ ให้อยู่นอกโฟลเดอร์ที่ย่อและโฟลเดอร์ Offload หากเว้นว่างจะไม่มีสำเนาต้นฉบับสำหรับกู้คืน</li>
                    <li>เริ่มงาน ระบบแทนที่ชื่อเดิมเฉพาะเมื่อขนาดลดลงอย่างน้อย 10% แล้วตรวจคุณภาพเอกสารหลังย่อ</li>
                </ol>
                <p>เปิดย่ออัตโนมัติเพื่อประมวลผล PDF ที่เพิ่มผ่าน File Manager เข้าโฟลเดอร์ที่เลือกก่อนส่งขึ้น Storage ผู้ดูแลย่อทีละไฟล์ได้จากเมนูคลิกขวา หากไฟล์อยู่บน Bucket แล้วจะอัปโหลดทับ key เดิม จึงอาจต้องล้าง cache ของ CDN เพื่อเห็นไฟล์ล่าสุด</p>
            </section>

            <section id="isxs-guide-assets" aria-labelledby="isxs-guide-assets-title">
                <h2 id="isxs-guide-assets-title">เสิร์ฟ CSS / JS ผ่าน CDN</h2>
                <p>ในแท็บ <strong>ทรัพยากร (Assets)</strong> เปิด <strong>ใช้ CDN สำหรับ CSS และ JavaScript</strong> และกรอกโดเมน CDN ที่ตั้ง origin เป็นเว็บไซต์ WordPress ของคุณ จากนั้นตรวจว่าหน้าเว็บยังแสดงผลและทำงานครบ</p>
                <p>การตั้งค่านี้เปลี่ยน URL ของ CSS/JS ไฟล์ธีมและปลั๊กอินยังอยู่บนเซิร์ฟเวอร์ ไม่ได้ส่งขึ้น Bucket โดเมน CDN สำหรับ Assets ตั้งแยกจากโดเมนเสิร์ฟ Media</p>
            </section>

            <section id="isxs-guide-backup" aria-labelledby="isxs-guide-backup-title">
                <h2 id="isxs-guide-backup-title">สำรองและกู้คืนเว็บ</h2>
                <p>เปิด <strong>InsightX Offload → สำรองข้อมูล (Backup)</strong> ส่วนนี้มีการเชื่อมต่อ Storage ของตัวเอง แยกจาก Connections ของ Offload</p>
                <?php $guide_figure( 'backup-real.png', 'หน้าสำรองข้อมูลจริงในแท็บส่งออก พร้อมตัวเลือกขั้นสูง ผู้ให้บริการ Storage และปุ่มส่งออกเป็นไฟล์หรือส่งออกไปยัง Storage', 'หน้าจอจริง: ส่งออกข้อมูลสำรองเว็บไซต์', 1280, 775 ); ?>
                <h3>สร้างข้อมูลสำรอง</h3>
                <ol>
                    <li>เปิดแท็บ <strong>ส่งออก</strong> เลือกข้อมูลที่ต้องการสำรองจากฐานข้อมูลและ <code>wp-content</code></li>
                    <li>เลือกเก็บในเครื่องหรือส่งขึ้น Storage หากต้องการ ใช้ตัวเลือกขั้นสูงเพื่อบีบอัด เข้ารหัสด้วยรหัสผ่าน หรือเลือกสิ่งที่ไม่รวม</li>
                    <li>เริ่มส่งออก รอจนเสร็จ แล้วดาวน์โหลดแพ็กเกจ <code>.wpress</code> เก็บไว้ ตรวจรายการได้ในแท็บ <strong>ข้อมูลสำรอง</strong></li>
                    <li>หากต้องการสำรองอัตโนมัติ ให้กำหนดตารางเวลาและจำนวนไฟล์ที่เก็บไว้ในแท็บ <strong>ตั้งค่า</strong></li>
                </ol>
                <h3>กู้คืนหรือย้ายเว็บ</h3>
                <ol>
                    <li>สำรองเว็บไซต์ปลายทางก่อน เพราะการนำเข้าจะเขียนทับไฟล์และฐานข้อมูล</li>
                    <li>เปิดแท็บ <strong>นำเข้า</strong> แล้วอัปโหลดแพ็กเกจหรือเลือกจาก Storage หรือเลือกกู้คืนจากแท็บ <strong>ข้อมูลสำรอง</strong></li>
                    <li>ทำตามขั้นตอนบนหน้าจอ รอให้เสร็จ แล้วตรวจหน้าเว็บ ลิงก์และการเข้าสู่ระบบ</li>
                    <li>หากมีปัญหา ให้ดูแท็บ <strong>Log</strong> เพื่ออ่านหรือดาวน์โหลดบันทึกการทำงาน</li>
                </ol>
                <p class="isxs-guide-note">ไฟล์สื่อที่ลบสำเนา local แล้วเหลือเฉพาะบน Storage ไม่ได้อยู่ใน <code>wp-content</code> ของเซิร์ฟเวอร์ ควรสำรองไฟล์บน Bucket แยกด้วย และเก็บรหัสผ่านของแพ็กเกจที่เข้ารหัสไว้ให้พร้อมใช้งาน</p>
                <p><strong>Reset Hub</strong> ใช้ล้างปลั๊กอิน ธีม สื่อ หรือฐานข้อมูล เป็นคำสั่งลบข้อมูล ไม่ใช่ขั้นตอนปกติในการสำรองเว็บ อ่านรายการที่จะลบและเก็บข้อมูลสำรองก่อนยืนยันด้วยรหัสผ่าน</p>
                <p>หากยังเปิดปลั๊กอิน InsightX Backup ตัวเดิมอยู่ ส่วนสำรองข้อมูลที่รวมใน Offload จะไม่โหลดซ้ำ ให้ย้ายมาใช้ตัวที่รวมไว้ตามข้อความแจ้งเตือน ข้อมูลและการตั้งค่าเดิมใช้ร่วมกันได้</p>
            </section>

            <section id="isxs-guide-troubleshooting" aria-labelledby="isxs-guide-troubleshooting-title">
                <h2 id="isxs-guide-troubleshooting-title">แก้ปัญหาที่พบบ่อย</h2>
                <details open><summary>เชื่อมต่อสำเร็จ แต่รูปหรือไฟล์เปิดไม่ได้</summary><p>ตรวจโดเมน CDN, HTTPS, path และสิทธิ์อ่านไฟล์ของ Bucket การทดสอบ API สำเร็จไม่ได้ยืนยันว่า public URL เปิดได้ ลองเปิด URL ไฟล์โดยตรงเพื่อแยกปัญหา</p></details>
                <details><summary>ไฟล์เก่ายังไม่ขึ้น Storage</summary><p>Offload Media ใช้กับไฟล์ใหม่ สำหรับไฟล์เดิมให้ไป Tools เลือกโฟลเดอร์ในการ์ด Offload media แล้วกด Start Offload ตรวจรายการล้มเหลวและใช้ Retry failed หลังแก้ปัญหา</p></details>
                <details><summary>วางไฟล์ผ่าน FTP แล้วไม่ขึ้น Storage ทันที</summary><p>เลือกโฟลเดอร์นั้นใน Tools และเปิด Offload Media เพื่อรอรอบตรวจรายชั่วโมง หรือกด Start Offload เพื่อเริ่มตอนนี้ การเพิ่มผ่าน File Manager ของปลั๊กอินจะส่งขึ้นทันทีตามสิทธิ์และโฟลเดอร์ที่อนุญาต</p></details>
                <details><summary>งานหยุดนิ่งหรือค้างเมื่อปิดหน้า</summary><p>ตรวจ loopback และ WP-Cron หากหน้าจอแจ้งให้เปิดแท็บไว้ ให้รอจนเสร็จ ผู้ดูแลเซิร์ฟเวอร์ตรวจงานด้วย <code>wp isxm job list</code> และใช้ <code>wp isxm job run --once</code> เพื่อประมวลผลงานที่เริ่มไว้แล้วได้</p></details>
                <details><summary>ย่อ PDF ไม่ได้ หรือยังเห็นไฟล์ขนาดเดิม</summary><p>ตรวจว่าเซิร์ฟเวอร์มี Ghostscript (<code>gs</code>), PHP เรียก <code>exec()</code> ได้ และเขียนไฟล์ได้ ต้องมี PDF บนเซิร์ฟเวอร์ หากไฟล์ใหม่ไม่เล็กลงอย่างน้อย 10% ระบบจะคงต้นฉบับ หากย่อสำเร็จแต่ URL เดิมยังแสดงไฟล์เก่า ให้ล้าง cache ของ CDN</p></details>
                <details><summary>ต้องการปิดหรือถอนการติดตั้งปลั๊กอิน</summary><p>ดาวน์โหลดไฟล์ที่ต้องการกลับด้วย Tools → Download แล้วตรวจว่าไฟล์ local และ URL บนเว็บไซต์ใช้งานได้ก่อนปิดปลั๊กอิน การถอนการติดตั้งไม่ดาวน์โหลดไฟล์จาก Bucket กลับให้อัตโนมัติ และจะลบการตั้งค่ากับข้อมูลติดตามของ Offload</p></details>
                <details><summary>ต้องส่งข้อมูลอะไรให้ผู้ดูแลเมื่อพบปัญหา</summary><p>เปิด Offload → ช่วยเหลือ (Support) → ข้อมูลสำหรับตรวจสอบปัญหา (Diagnostics) พร้อมระบุขั้นตอนที่ทำและข้อความผิดพลาด หากเกี่ยวกับ Backup ให้แนบ Log ของงาน ตรวจและปิดบังรหัสผ่าน Key และข้อมูลส่วนตัวก่อนส่ง</p></details>
            </section>
            <a class="isxs-guide-back-top" href="#isxs-guide-top">กลับไปด้านบน</a>
        </article>
    </div>
</div>
