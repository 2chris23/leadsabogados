(function ($) {
  "use strict";

  // sidebar submenu collapsible js
  $(".sidebar-menu .dropdown").on("click", function () {
    var item = $(this);
    item.siblings(".dropdown").children(".sidebar-submenu").slideUp();

    item.siblings(".dropdown").removeClass("dropdown-open");

    item.siblings(".dropdown").removeClass("open");

    item.children(".sidebar-submenu").slideToggle();

    item.toggleClass("dropdown-open");
  });

  $(".sidebar-toggle").on("click", function () {
    $(this).toggleClass("active");
    $(".sidebar").toggleClass("active");
    $(".dashboard-main").toggleClass("active");
  });

  $(".sidebar-mobile-toggle").on("click", function () {
    $(".sidebar").addClass("sidebar-open");
    $("body").addClass("overlay-active");
  });

  $(".sidebar-close-btn").on("click", function () {
    $(".sidebar").removeClass("sidebar-open");
    $("body").removeClass("overlay-active");
  });

  //to keep the current page active
  $(function () {
    for (
      var nk = window.location,
        o = $("ul#sidebar-menu a")
          .filter(function () {
            return this.href == nk;
          })
          .addClass("active-page") // anchor
          .parent()
          .addClass("active-page");
      ;

    ) {
      // li
      if (!o.is("li")) break;
      o = o.parent().addClass("show").parent().addClass("open");
    }
  });

  // =========================== Force Light Mode (dark mode disabled) ================================
  localStorage.removeItem('theme');
  document.querySelector('html').setAttribute('data-theme', 'light');
  // =========================== Force Light Mode End ================================


  // =========================== Theme Customization Show Hide js Start ================================
  $(".theme-customization__button").on("click", function () {
    $(".theme-customization-sidebar").toggleClass("active");
    $(".body-overlay").toggleClass("show");
  });

  $(".theme-customization-sidebar__close, .body-overlay").on(
    "click",
    function () {
      $(".theme-customization-sidebar").removeClass("active");
      $(".body-overlay").removeClass("show");
    }
  );
  // =========================== Theme Customization Show Hide js End ================================

  // =========================== RTL Mode js Start ================================
  $(".ltr-mode-btn").on("click", function () {
    $("html").attr("dir", "ltr");
    localStorage.setItem("direction", "ltr");

    // Toggle active state
    $(".theme-setting-item__btn").removeClass("active");
    $(this).addClass("active");
  });

  // RTL button
  $(".rtl-mode-btn").on("click", function () {
    $("html").attr("dir", "rtl");
    localStorage.setItem("direction", "rtl");

    // Toggle active state
    $(".theme-setting-item__btn").removeClass("active");
    $(this).addClass("active");
  });

  // Load saved direction from localStorage on page load
  $(document).ready(function () {
    const savedDir = localStorage.getItem("direction");
    if (savedDir) {
      $("html").attr("dir", savedDir);

      // Keep correct button active
      if (savedDir === "rtl") {
        $(".rtl-mode-btn").addClass("active");
      } else {
        $(".ltr-mode-btn").addClass("active");
      }
    }
  });
  // =========================== RTL Mode js End ================================

  // =========================== Color Schema js Start ================================
  // const colorPickerButtons = document.querySelectorAll(".color-picker-btn");

  // const colors = {
  //   blue: "#2563eb",
  //   red: "#dc2626",
  //   green: "#16a34a",
  //   yellow: "#ff9f29",
  //   cyan: "#00b8f2",
  //   violet: "#7c3aed",
  // };

  // function applyColor(color) {
  //   document.documentElement.style.setProperty("--primary-600", colors[color]);
  //   localStorage.setItem("templateColor", color);
  // }

  // colorPickerButtons.forEach((btn) => {
  //   btn.addEventListener("click", () => {
  //     const color = btn.getAttribute("data-color");

  //     // Apply color
  //     applyColor(color);

  //     // Active state
  //     colorPickerButtons.forEach((b) => b.classList.remove("active"));
  //     btn.classList.add("active");
  //   });
  // });

  // // Load saved color on refresh
  // const savedColor = localStorage.getItem("templateColor");
  // if (savedColor && colors[savedColor]) {
  //   applyColor(savedColor);
  //   document
  //     .querySelector(`.color-picker-btn[data-color="${savedColor}"]`)
  //     .classList.add("active");
  // } else {
  //   // Default (blue)
  //   document
  //     .querySelector(`.color-picker-btn[data-color="blue"]`)
  //     .classList.add("active");
  // }

  const colorPickerButtons = document.querySelectorAll(".color-picker-btn");

  const colors = {
    blue: "#2563eb",
    red: "#dc2626",
    green: "#16a34a",
    yellow: "#ff9f29",
    cyan: "#00b8f2",
    violet: "#7c3aed",
  };

  function applyColor(color) {
    document.documentElement.style.setProperty("--primary-600", colors[color]);
    localStorage.setItem("templateColor", color);
  }

  colorPickerButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const color = btn.getAttribute("data-color");

      if (!color) return; // safety

      // Apply color
      applyColor(color);

      // Instantly update active state
      colorPickerButtons.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
    });
  });

  // Load saved color on refresh
  const savedColor = localStorage.getItem("templateColor");
  if (savedColor && colors[savedColor]) {
    applyColor(savedColor);
    const savedBtn = document.querySelector(`.color-picker-btn[data-color="${savedColor}"]`);
    if (savedBtn) savedBtn.classList.add("active");
  } else {
    // Default (blue)
    const defaultBtn = document.querySelector(`.color-picker-btn[data-color="blue"]`);
    if (defaultBtn) defaultBtn.classList.add("active");
  }
  // =========================== Color Schema js End ================================

  // =========================== Table Header Checkbox checked all js Start ================================
  $("#selectAll").on("change", function () {
    $(".form-check .form-check-input").prop("checked", $(this).prop("checked"));
  });

  // Remove Table Tr when click on remove btn start
  $(".remove-btn").on("click", function () {
    $(this).closest("tr").remove();

    // Check if the table has no rows left
    if ($(".table tbody tr").length === 0) {
      $(".table").addClass("bg-danger");

      // Show notification
      $(".no-items-found").show();
    }
  });
  // Remove Table Tr when click on remove btn end

  // =========================== Detector de conexión Start ================================
  (function() {
    // Crear banner de aviso
    const banner = document.createElement('div');
    banner.id = 'offline-banner';
    banner.style.cssText = [
      'position:fixed', 'top:0', 'left:0', 'right:0', 'z-index:99999',
      'background:#dc2626', 'color:#fff', 'text-align:center',
      'padding:10px 16px', 'font-size:14px', 'font-weight:600',
      'display:none', 'align-items:center', 'justify-content:center', 'gap:8px'
    ].join(';');
    banner.innerHTML = '⚠️ Sin conexión a internet — no envíes formularios hasta que se restablezca la conexión';
    document.body.appendChild(banner);

    function setOnline()  { banner.style.display = 'none'; }
    function setOffline() { banner.style.display = 'flex'; }

    window.addEventListener('online',  setOnline);
    window.addEventListener('offline', setOffline);
    if (!navigator.onLine) setOffline();
  })();
  // =========================== Detector de conexión End ================================

  // =========================== Anti doble-submit Start ================================
  // Deshabilita el botón submit mientras el formulario se está enviando
  document.addEventListener('submit', function(e) {
    const form = e.target;
    const btn = form.querySelector('[type="submit"]');
    if (!btn) return;
    
    // Si otro script ya previno el envío (ej: el modal de confirmación), no mostrar "Enviando..."
    if (e.defaultPrevented) return;

    if (!navigator.onLine) {
      e.preventDefault();
      alert('No hay conexión a internet. Espera a que se restablezca antes de enviar.');
      return;
    }
    
    setTimeout(() => {
      btn.disabled = true;
      btn.dataset.originalText = btn.innerHTML;
      if (btn.classList.contains('w-32-px')) {
          // Botón circular pequeño, solo poner un spinner
          btn.innerHTML = '<iconify-icon icon="line-md:loading-twotone-loop" style="font-size:18px"></iconify-icon>';
      } else {
          // Botón normal
          btn.innerHTML = '<span style="opacity:.7">Enviando...</span>';
      }
    }, 10);
  });
  // =========================== Anti doble-submit End ================================

})(jQuery);
