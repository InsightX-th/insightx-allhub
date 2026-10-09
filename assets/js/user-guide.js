/** Reading navigation only; this page never loads the Offload job controls. */
(() => {
  "use strict";
  const guide = document.querySelector(".isxs-guide");
  if (!guide) return;

  const links = [...guide.querySelectorAll(".isxs-guide-toc a")];
  const sections = [...guide.querySelectorAll(".isxs-guide-content section[id]")];
  const select = guide.querySelector(".isxs-guide-topic-select");
  if (!links.length || !sections.length || !select) return;

  guide.classList.add("is-enhanced");
  let scheduled = false;
  const update = () => {
    scheduled = false;
    let current = sections[0];
    const toolbar = document.getElementById("wpadminbar");
    const offset = (toolbar ? toolbar.getBoundingClientRect().height : 0) + 100;
    for (const section of sections) {
      if (section.getBoundingClientRect().top <= offset) current = section;
    }
    // A short final section cannot reach the top once the page ends.
    if (window.scrollY > 0 && window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2) {
      current = sections[sections.length - 1];
    }
    for (const link of links) {
      if (link.hash === `#${current.id}`) link.setAttribute("aria-current", "location");
      else link.removeAttribute("aria-current");
    }
    // Keep arrow-key selection stable while someone is using the mobile menu.
    if (document.activeElement !== select) select.value = current.id;
  };
  const scheduleUpdate = () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(update);
  };
  window.addEventListener("scroll", scheduleUpdate, { passive: true });
  window.addEventListener("resize", scheduleUpdate);
  window.addEventListener("hashchange", scheduleUpdate);
  select.addEventListener("change", () => {
    const section = sections.find((item) => item.id === select.value);
    if (section) window.location.hash = section.id;
  });
  update();
})();
