/* Khulna Polytechnic Institute — site interactions (vanilla JS) */
(function () {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  document.documentElement.classList.add("js");

  /* ---------- Sticky header + back-to-top ---------- */
  const header = $(".site-header");
  const toTop = $(".to-top");
  const onScroll = () => {
    const y = window.scrollY;
    if (header) header.classList.toggle("scrolled", y > 40);
    if (toTop) toTop.classList.toggle("show", y > 700);
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();
  if (toTop) toTop.addEventListener("click", () => window.scrollTo({ top: 0, behavior: "smooth" }));

  /* ---------- Mobile menu ---------- */
  const toggle = $(".menu-toggle");
  const scrim = $(".scrim");
  const closeMenu = () => {
    document.body.classList.remove("menu-open");
    if (toggle) toggle.setAttribute("aria-expanded", "false");
  };
  if (toggle) {
    toggle.addEventListener("click", () => {
      const open = document.body.classList.toggle("menu-open");
      toggle.setAttribute("aria-expanded", String(open));
    });
  }
  if (scrim) scrim.addEventListener("click", closeMenu);
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeMenu(); });

  // On small screens the first tap on a dropdown parent opens its submenu
  $$(".has-drop > a").forEach((a) => {
    a.addEventListener("click", (e) => {
      if (window.innerWidth > 980) return;
      const li = a.parentElement;
      if (!li.classList.contains("open")) {
        e.preventDefault();
        li.classList.add("open");
      }
    });
  });

  /* ---------- Scroll reveal ---------- */
  const reveals = $$(".reveal");
  if ("IntersectionObserver" in window && !reduceMotion) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) {
          en.target.classList.add("in");
          io.unobserve(en.target);
        }
      });
    }, { threshold: 0.14, rootMargin: "0px 0px -40px 0px" });
    reveals.forEach((el) => io.observe(el));
  } else {
    reveals.forEach((el) => el.classList.add("in"));
  }

  /* ---------- Animated counters ---------- */
  const counters = $$("[data-count]");
  const formatNum = (n, decimals) => n.toLocaleString("en-US", { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  const runCounter = (el) => {
    const target = parseFloat(el.dataset.count);
    const decimals = (el.dataset.count.split(".")[1] || "").length;
    const suffix = el.dataset.suffix || "";
    const plain = el.hasAttribute("data-plain");
    if (reduceMotion) { el.textContent = (plain ? target : formatNum(target, decimals)) + suffix; return; }
    const dur = 1600;
    const start = performance.now();
    const tick = (now) => {
      const p = Math.min((now - start) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      const val = target * eased;
      el.textContent = (plain ? Math.round(val) : formatNum(val, decimals)) + suffix;
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };
  if (counters.length && "IntersectionObserver" in window) {
    const cio = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) { runCounter(en.target); cio.unobserve(en.target); }
      });
    }, { threshold: 0.6 });
    counters.forEach((c) => cio.observe(c));
  }

  /* ---------- Technology list: cursor-following image preview ---------- */
  const preview = $(".hover-preview");
  if (preview && window.matchMedia("(hover: hover) and (min-width: 981px)").matches) {
    const img = $("img", preview);
    let x = 0, y = 0, cx = 0, cy = 0, raf = null;
    const follow = () => {
      cx += (x - cx) * 0.18;
      cy += (y - cy) * 0.18;
      preview.style.left = cx + "px";
      preview.style.top = cy + "px";
      raf = requestAnimationFrame(follow);
    };
    $$(".tech-row").forEach((row) => {
      row.addEventListener("mouseenter", (e) => {
        img.src = row.dataset.img;
        img.alt = "";
        x = cx = e.clientX + 170;
        y = cy = e.clientY;
        preview.classList.add("show");
        if (!raf) follow();
      });
      row.addEventListener("mousemove", (e) => { x = e.clientX + 170; y = e.clientY; });
      row.addEventListener("mouseleave", () => {
        preview.classList.remove("show");
        cancelAnimationFrame(raf);
        raf = null;
      });
    });
  }

  /* ---------- Notice tabs (home + notices page) ---------- */
  $$("[data-tabs]").forEach((group) => {
    const list = $(group.dataset.tabs);
    if (!list) return;
    group.addEventListener("click", (e) => {
      const btn = e.target.closest(".tab");
      if (!btn) return;
      $$(".tab", group).forEach((t) => t.classList.toggle("active", t === btn));
      list.dataset.filter = btn.dataset.cat;
      applyNoticeFilter(list);
    });
  });

  const search = $("#notice-search");
  if (search) {
    search.addEventListener("input", () => applyNoticeFilter($(search.dataset.target)));
  }

  function applyNoticeFilter(list) {
    const cat = list.dataset.filter || "all";
    const q = search && search.dataset.target && list.matches(search.dataset.target) ? search.value.trim().toLowerCase() : "";
    let shown = 0;
    $$("[data-cat]", list).forEach((item) => {
      const okCat = cat === "all" || item.dataset.cat === cat;
      const okQ = !q || item.textContent.toLowerCase().includes(q);
      const show = okCat && okQ;
      item.style.display = show ? "" : "none";
      if (show) shown++;
    });
    const empty = $(".empty", list.closest("section") || document);
    if (empty) empty.style.display = shown ? "none" : "block";
  }

  /* ---------- Department filter (departments page) ---------- */
  const deptFilter = $(".dept-filter");
  if (deptFilter) {
    deptFilter.addEventListener("click", (e) => {
      const btn = e.target.closest(".tab");
      if (!btn) return;
      $$(".tab", deptFilter).forEach((t) => t.classList.toggle("active", t === btn));
      const g = btn.dataset.group;
      $$(".dept-card").forEach((c) => c.classList.toggle("dim", g !== "all" && c.dataset.group !== g));
    });
  }

  /* ---------- Gallery lightbox ---------- */
  const gallery = $$(".gallery button");
  const lb = $(".lightbox");
  if (gallery.length && lb) {
    const lbImg = $("img", lb);
    const lbCap = $("figcaption", lb);
    let idx = 0;
    const show = (i) => {
      idx = (i + gallery.length) % gallery.length;
      const src = gallery[idx].querySelector("img");
      lbImg.src = src.src;
      lbImg.alt = src.alt;
      lbCap.textContent = src.alt;
    };
    const open = (i) => { show(i); lb.classList.add("open"); document.body.style.overflow = "hidden"; $(".lb-close", lb).focus(); };
    const close = () => { lb.classList.remove("open"); document.body.style.overflow = ""; gallery[idx].focus(); };
    gallery.forEach((b, i) => b.addEventListener("click", () => open(i)));
    $(".lb-close", lb).addEventListener("click", close);
    $(".lb-prev", lb).addEventListener("click", () => show(idx - 1));
    $(".lb-next", lb).addEventListener("click", () => show(idx + 1));
    lb.addEventListener("click", (e) => { if (e.target === lb) close(); });
    document.addEventListener("keydown", (e) => {
      if (!lb.classList.contains("open")) return;
      if (e.key === "Escape") close();
      if (e.key === "ArrowLeft") show(idx - 1);
      if (e.key === "ArrowRight") show(idx + 1);
    });
  }

  /* ---------- Contact form validation ---------- */
  const form = $("#contact-form");
  if (form) {
    const rules = {
      name: (v) => v.trim().length >= 3,
      email: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()),
      phone: (v) => v.trim() === "" || /^(\+?88)?01[3-9]\d{8}$/.test(v.replace(/[\s-]/g, "")),
      topic: (v) => v !== "",
      message: (v) => v.trim().length >= 20
    };
    const check = (field) => {
      const rule = rules[field.name];
      if (!rule) return true;
      const ok = rule(field.value);
      field.closest(".field").classList.toggle("invalid", !ok);
      return ok;
    };
    $$("input, select, textarea", form).forEach((f) => {
      f.addEventListener("blur", () => check(f));
      f.addEventListener("input", () => { if (f.closest(".field").classList.contains("invalid")) check(f); });
    });
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      const fields = $$("input, select, textarea", form);
      const allOk = fields.map(check).every(Boolean);
      if (!allOk) { fields.find((f) => f.closest(".field").classList.contains("invalid")).focus(); return; }
      $(".form-ok", form).classList.add("show");
      form.reset();
    });
  }

  /* ---------- Footer year ---------- */
  $$("[data-year]").forEach((el) => { el.textContent = new Date().getFullYear(); });
})();
