(() => {
  const storeUrl = "https://play.google.com/store/apps/details?id=com.timebill.stopwatch";
  let dialog;

  function createDialog() {
    dialog = document.createElement("dialog");
    dialog.className = "timebill-start-dialog";
    dialog.setAttribute("aria-labelledby", "timebill-start-title");
    dialog.innerHTML = `
      <style>
        .timebill-start-dialog {
          width: min(420px, calc(100vw - 32px)); padding: 26px; border: 1px solid #e4d8c8;
          border-radius: 14px; background: #fff8f6; color: #2a1614;
          box-shadow: 0 20px 60px rgba(46, 26, 23, .24); font-family: Inter, sans-serif;
        }
        .timebill-start-dialog::backdrop { background: rgba(32, 21, 18, .5); backdrop-filter: blur(3px); }
        .timebill-start-dialog h2 { margin: 0 0 7px; color: #4a231f; font-size: 20px; font-weight: 600; }
        .timebill-start-dialog p { margin: 0 0 21px; color: #514442; font-size: 14px; line-height: 1.55; }
        .timebill-start-options { display: grid; gap: 10px; }
        .timebill-start-options a {
          min-height: 48px; display: flex; align-items: center; justify-content: center; padding: 10px 15px;
          border: 1px solid #4a231f; border-radius: 8px; background: #4a231f; color: #fff;
          font-size: 14px; font-weight: 600; text-align: center; text-decoration: none;
        }
        .timebill-start-options a + a { border-color: #e4d8c8; background: transparent; color: #4a231f; }
        .timebill-start-options a:hover { opacity: .88; }
        .timebill-start-close { display: block; margin: 13px auto 0; padding: 7px 12px; border: 0; background: transparent; color: #514442; font: inherit; font-size: 13px; cursor: pointer; }
      </style>
      <h2 id="timebill-start-title">Get Started with TimeBill</h2>
      <p>Choose how you would like to continue.</p>
      <div class="timebill-start-options">
        <a href="/start.html">Continue in Website</a>
        <a href="${storeUrl}" target="_blank" rel="noopener noreferrer">Download on Google Play</a>
      </div>
      <button class="timebill-start-close" type="button">Close</button>
    `;
    dialog.querySelector(".timebill-start-close").addEventListener("click", () => dialog.close());
    dialog.addEventListener("click", (event) => { if (event.target === dialog) dialog.close(); });
    document.body.append(dialog);
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[href]");
    if (!link || (!link.hasAttribute("data-get-started") && (link.getAttribute("href") !== storeUrl || link.textContent.trim() !== "Get Started"))) return;
    event.preventDefault();
    if (!dialog) createDialog();
    dialog.showModal();
  });
})();