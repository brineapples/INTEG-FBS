/* Admin JS - Survey System */
"use strict";

function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add("active");
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove("active");
}

function confirmDelete(formId, itemName) {
  if (confirm('Delete "' + itemName + '"? This cannot be undone.')) {
    document.getElementById(formId).submit();
  }
}

let optionCount = 0;
const closeIconSvg =
  '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>';

function addOption() {
  const container = document.getElementById("options-container");
  if (!container) return;

  optionCount++;
  const div = document.createElement("div");
  div.className = "option-row";
  div.style.cssText = "display:flex;gap:8px;align-items:center;margin-bottom:8px;";
  div.innerHTML =
    '<input type="text" name="options[]" placeholder="Option ' +
    optionCount +
    '" class="form-control" required>' +
    '<button type="button" onclick="this.parentElement.remove()" class="btn btn-danger btn-sm" aria-label="Remove option">' +
    closeIconSvg +
    "</button>";
  container.appendChild(div);
}

function toggleQuestionType(type) {
  const optBlock = document.getElementById("options-block");
  if (!optBlock) return;

  optBlock.style.display = type === "mcq" ? "block" : "none";
  document
    .querySelectorAll('#options-container input[name="options[]"]')
    .forEach((input) => {
      input.required = type === "mcq";
    });
}

document.addEventListener("click", function (event) {
  if (event.target.classList.contains("modal-overlay")) {
    event.target.classList.remove("active");
  }

  const sidebar = document.getElementById("sidebar");
  const toggle = event.target.closest("[data-sidebar-toggle]");
  if (
    sidebar &&
    sidebar.classList.contains("open") &&
    !sidebar.contains(event.target) &&
    !toggle
  ) {
    sidebar.classList.remove("open");
  }
});

document.addEventListener("keydown", function (event) {
  if (event.key === "Escape") {
    document
      .querySelectorAll(".modal-overlay.active")
      .forEach((modal) => modal.classList.remove("active"));
  }
});

document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll("[data-sidebar-toggle]").forEach((button) => {
    button.addEventListener("click", function () {
      const sidebar = document.getElementById("sidebar");
      if (sidebar) sidebar.classList.toggle("open");
    });
  });

  const typeSelect = document.getElementById("questionType");
  if (typeSelect) {
    toggleQuestionType(typeSelect.value);
    typeSelect.addEventListener("change", () => toggleQuestionType(typeSelect.value));
  }

  const flash = document.querySelector(".flash");
  if (flash) {
    setTimeout(() => {
      flash.style.opacity = "0";
      flash.style.transition = "opacity .5s";
    }, 4000);
  }

  document.querySelectorAll("th[data-sort]").forEach((th) => {
    th.style.cursor = "pointer";
  });
});

function printReport() {
  window.print();
}
