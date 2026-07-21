import { initializeApp, getApps } from "https://www.gstatic.com/firebasejs/10.9.0/firebase-app.js";
import { getDatabase, ref, push, serverTimestamp } from "https://www.gstatic.com/firebasejs/10.9.0/firebase-database.js";

const firebaseConfig = {
  projectId: "timebill-icc",
  databaseURL: "https://timebill-icc-default-rtdb.firebaseio.com"
};

let app;
let db;
let useRestFallback = false;

try {
  app = getApps().length ? getApps()[0] : initializeApp(firebaseConfig);
  db = getDatabase(app);
} catch (error) {
  useRestFallback = true;
}

export function getServerTimestampValue() {
  return typeof serverTimestamp === "function" ? serverTimestamp() : { ".sv": "timestamp" };
}

export function showDialog({ title, message, type = "success" }) {
  const existingDialog = document.getElementById("timebill-form-dialog");
  if (existingDialog) {
    existingDialog.remove();
  }

  const overlay = document.createElement("div");
  overlay.id = "timebill-form-dialog";
  overlay.setAttribute("role", "dialog");
  overlay.setAttribute("aria-modal", "true");
  overlay.style.position = "fixed";
  overlay.style.inset = "0";
  overlay.style.background = "rgba(17, 24, 39, 0.55)";
  overlay.style.display = "flex";
  overlay.style.alignItems = "center";
  overlay.style.justifyContent = "center";
  overlay.style.padding = "20px";
  overlay.style.zIndex = "99999";

  const accentColor = type === "error" ? "#b91c1c" : "#4A231F";
  const accentBg = type === "error" ? "#fef2f2" : "#f3e8dc";

  overlay.innerHTML = `
    <div style="width:100%;max-width:420px;background:#fff8f6;border:1px solid #e4d8c8;border-radius:1rem;box-shadow:0 18px 45px rgba(74,35,31,0.16);padding:24px 24px 20px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
        <div style="width:40px;height:40px;border-radius:9999px;display:grid;place-items:center;background:${accentBg};color:${accentColor};font-size:20px;">
          ${type === "error" ? "⚠" : "✓"}
        </div>
        <h3 style="font-size:1.1rem;font-weight:700;color:#4A231F;margin:0;">${title}</h3>
      </div>
      <p style="margin:0 0 16px;color:#514442;line-height:1.6;white-space:pre-line;">${message}</p>
      <div style="display:flex;justify-content:flex-end;">
        <button type="button" style="background:#4A231F;color:#fff;border:none;border-radius:999px;padding:10px 16px;font-weight:600;cursor:pointer;">Close</button>
      </div>
    </div>
  `;

  overlay.addEventListener("click", (event) => {
    if (event.target === overlay) {
      overlay.remove();
    }
  });

  overlay.querySelector("button").addEventListener("click", () => overlay.remove());
  document.body.appendChild(overlay);
}

export function setButtonLoading(button, isSubmitting) {
  if (!button) {
    return;
  }

  button.disabled = isSubmitting;

  if (isSubmitting) {
    button.dataset.originalText = button.textContent.trim();
    button.textContent = "Submitting...";
  } else if (button.dataset.originalText) {
    button.textContent = button.dataset.originalText;
  }
}

export function validateEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

async function submitEntryToDatabase(path, payload) {
  if (!useRestFallback && db) {
    const savedRef = await push(ref(db, path), payload);
    return savedRef;
  }

  const endpoint = `${firebaseConfig.databaseURL}/${path}.json`;
  const response = await fetch(endpoint, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });

  if (!response.ok) {
    throw new Error("Unable to save form data");
  }

  return response.json();
}

export async function handleFormSubmission({
  form,
  submitButton,
  path,
  requiredFields = [],
  buildPayload,
  successTitle = "Request Submitted",
  successMessage = "Thank you.\nYour request has been submitted successfully.\nOur support team will review it soon."
}) {
  if (!form || !submitButton) {
    return;
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (form.dataset.isSubmitting === "true") {
      return;
    }

    const formData = new FormData(form);
    const values = Object.fromEntries(formData.entries());
    const missingFields = requiredFields.filter((field) => !String(values[field] || "").trim());

    if (missingFields.length > 0) {
      showDialog({
        title: "Missing Information",
        message: "Please complete all required fields before submitting.",
        type: "error"
      });
      return;
    }

    const emailValue = String(values.email || "").trim();
    if (requiredFields.includes("email") && emailValue && !validateEmail(emailValue)) {
      showDialog({
        title: "Invalid Email",
        message: "Please enter a valid email address.",
        type: "error"
      });
      return;
    }

    form.dataset.isSubmitting = "true";
    setButtonLoading(submitButton, true);

    try {
      const payload = buildPayload(values);
      await submitEntryToDatabase(path, payload);
      showDialog({ title: successTitle, message: successMessage, type: "success" });
      form.reset();
    } catch (error) {
      showDialog({
        title: "Something went wrong",
        message: "Please check your internet connection and try again.",
        type: "error"
      });
    } finally {
      form.dataset.isSubmitting = "false";
      setButtonLoading(submitButton, false);
    }
  });
}
