document.addEventListener("DOMContentLoaded", function () {
  const switcher = document.getElementById("ezd_selected_comment_switcher");

  // --- Cookie helpers
  function setCookie(name, value, days = 30) {
    const expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
    document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + expires + "; path=/";
  }
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }

  // --- State (outer scope so switcher handler can access)
  let commentsFeatureInitialized = false;
  let activePopup = null;
  let activeBtn = null;
  let documentClickListenerAdded = false;

  // --- The main feature code
  function enableCommentsFeature() {
    // Prevent rebuilding UI more than once
    if (commentsFeatureInitialized) {
      // If UI already initialized, just show hidden elements (if any)
      document.querySelectorAll(".ezd_selected-comment-btn, .ezd_selected-popup")
        .forEach(el => el.style.display = "");
      return;
    }
    commentsFeatureInitialized = true;

    const settings = eazydocs_ajax_search.ezd_selected_comment_data || {};
    const presets = Array.isArray(settings.options) ? settings.options : [];
    const heading = settings.heading || "";
    const formTitle = settings.form_title || "";
    const subheading = settings.subheading || "";
    const footer = settings.footer || "";
    const other_label = settings.other_label || "";

    // one document click handler (close popup when clicking outside)
    if (!documentClickListenerAdded) {
      document.addEventListener("click", function (e) {
        if (activePopup && !activePopup.contains(e.target) && (!activeBtn || !activeBtn.contains(e.target))) {
          activePopup.classList.remove("active");
          if (activeBtn) {
            activeBtn.classList.remove("active-btn");

            const para = activeBtn.closest("p.ezd_selected-commentable");
            if (para) para.classList.remove("active-para");

            const openIcon = activeBtn.querySelector(".open-icon");
            if (openIcon) openIcon.style.display = "inline";
            const closeIcon = activeBtn.querySelector(".close-icon");
            if (closeIcon) closeIcon.style.display = "none";
          }
          activePopup = null;
          activeBtn = null;
        }
      });
      documentClickListenerAdded = true;
    }

    document.querySelectorAll(".ezd_selected-commentable").forEach(function (p) {
      // avoid duplicate insertion
      if (p.querySelector(".ezd_selected-comment-btn")) return;

      const paraId = p.getAttribute("data-para-id");

      // --- Add comment button
      const btn = document.createElement("span");
      btn.classList.add("ezd_selected-comment-btn");
      btn.innerHTML = `
        <span class="svg-container">
          <svg class="open-icon" viewBox="0 0 32 32" width="30px">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"></path>
            <path d="M11 5h2v6h-2zm0 8h2v2h-2z"></path>
          </svg>
        </span>
      `;
      p.appendChild(btn);

      // --- Build popup
      const popup = document.createElement("div");
      popup.classList.add("ezd_selected-popup");

      // --- Options HTML
      let optionsHTML = "";
      if (presets.length > 0) {
        presets.forEach((opt, index) => {
          const safeValue = String(opt).replace(/"/g, "&quot;");
          const checked = index === 0 ? "checked" : "";
          optionsHTML += `<label><input type="radio" name="option" value="${safeValue}" ${checked}> ${opt}</label>`;
        });
        if (other_label) optionsHTML += `<label><input type="radio" name="option" value="${other_label}">${other_label}</label>`;
      }

      const headingHTML = heading && presets.length > 0 ? `<div class="ezd-comment-form-heading">${heading}</div>` : "";

      popup.innerHTML = `
        <div class="tabs">
          <button class="tab-btn active" data-tab="form">Add Comment</button>
          <button class="tab-btn" data-tab="list">Comments</button>
        </div>
        <div class="tab-content active" id="form">
          ${headingHTML}
          <form class="ezd_selected-comment-form">
            <div class="options">${optionsHTML}</div>
            ${formTitle ? `<div class="ezd-comment-form-heading inline-heading">${formTitle}</div>` : ""}
            <textarea placeholder="Your comment..." ${presets.length === 0 ? "required" : ""}></textarea>
            ${subheading ? `<span class="ezd-form-info-text">${subheading}</span>` : ""}
            <div class="buttons">
              <button type="button" class="cancel-btn">Cancel</button>
              <button type="submit">Submit</button>
            </div>
          </form>
          ${footer ? `<div class="ezd-comment-form-footer">${footer}</div>` : ""}
        </div>
        <div class="tab-content" id="list">
          <ul class="ezd_selected-comments-list"><li class="ezd-comment-no-items">No comments yet.</li></ul>
        </div>
      `;
      p.appendChild(popup);

      // --- Option change logic
      const textarea = popup.querySelector("textarea");
      if (presets.length > 0) {
        popup.querySelectorAll("input[name=option]").forEach((radio) => {
          radio.addEventListener("change", function () {
            if (String(this.value) === other_label) {
              textarea.required = true;
              textarea.placeholder = "Please describe...";
            } else {
              textarea.required = false;
              textarea.placeholder = "Optional comment...";
            }
          });
        });
      }

      // --- Load existing comments (from data-comments attribute)
      const existingCommentsJson = p.getAttribute("data-comments");
      if (existingCommentsJson) {
        try {
          const list = popup.querySelector(".ezd_selected-comments-list");
          list.innerHTML = "";
          JSON.parse(existingCommentsJson).forEach((c) => {
            const li = document.createElement("li");
            li.innerHTML = `
              <div class="ezd_selected-comment">
                <img src="${c.avatar}" class="ezd_selected-avatar">
                <div>
                  <strong>${c.author}</strong>
                  <span>${c.option || ""}</span>
                  <div>${c.text}</div>
                </div>
              </div>`;
            list.appendChild(li);
          });
        } catch (err) {
          console.error("Failed to parse comments", err);
        }
      }

      // --- Toggle popup (use btn click -- safer than nested selection)
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        if (activePopup === popup && popup.classList.contains("active")) return;
        if (activePopup && activePopup !== popup) {
          activePopup.classList.remove("active");
          if (activeBtn) {
            const openIcon = activeBtn.querySelector(".open-icon");
            if (openIcon) openIcon.style.display = "inline";
            activeBtn.classList.remove("active-btn");

            const prevPara = activeBtn.closest("p.ezd_selected-commentable");
            if (prevPara) prevPara.classList.remove("active-para");
          }
        }
        popup.classList.add("active");
        popup.setAttribute("data-para-id", paraId);
        activePopup = popup;
        activeBtn = btn;
        document.querySelectorAll(".ezd_selected-comment-btn").forEach(b => b.classList.remove("active-btn"));
        btn.classList.add("active-btn");
        
        // Sync parent <p> with active state
        document.querySelectorAll("p.ezd_selected-commentable").forEach(pp => pp.classList.remove("active-para"));
        p.classList.add("active-para");

      });

      // --- Cancel button
      popup.querySelector(".cancel-btn").addEventListener("click", function () {
        popup.classList.remove("active");
        btn.classList.remove("active-btn");
        p.classList.remove("active-para");

        const para = activeBtn.closest("p.ezd_selected-commentable");
        if (para) para.classList.remove("active-para");

        if (activeBtn) {
          const openIcon = activeBtn.querySelector(".open-icon");
          if (openIcon) openIcon.style.display = "inline";
        }
        activePopup = null;
        activeBtn = null;
      });

      // --- Tabs
      popup.querySelectorAll(".tab-btn").forEach((tabBtn) => {
        tabBtn.addEventListener("click", function () {
          popup.querySelectorAll(".tab-btn").forEach((b) => b.classList.remove("active"));
          popup.querySelectorAll(".tab-content").forEach((tc) => tc.classList.remove("active"));
          this.classList.add("active");
          popup.querySelector("#" + this.dataset.tab).classList.add("active");
        });
      });

      // --- Submit
      popup.querySelector(".ezd_selected-comment-form").addEventListener("submit", function (e) {
        e.preventDefault();
        const text = textarea.value.trim();
        const option = presets.length > 0 ? popup.querySelector("input[name=option]:checked").value : "";

        if (presets.length > 0 && option === "Other" && !text) {
          alert("Please provide a comment for 'Other'.");
          return;
        }
        if (presets.length === 0 && !text) {
          alert("Please provide a comment.");
          return;
        }

        const submitBtn = this.querySelector("button[type=submit]");
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = "Submitting...";

        fetch(eazydocs_ajax_search.ajax_url, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({
            action: "ezd_selected_save_comment",
            post_id: eazydocs_ajax_search.docs_id,
            para_id: paraId,
            content: text,
            option: option,
          }),
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            const list = popup.querySelector(".ezd_selected-comments-list");
            if (list.querySelector("li") && list.querySelector("li").innerText === "No comments yet.") list.innerHTML = "";
            const li = document.createElement("li");
            li.innerHTML = `
              <div class="ezd_selected-comment">
                <img src="${data.data.avatar}" class="ezd_selected-avatar">
                <div>
                  <strong>${data.data.author}</strong>
                  <span>${data.data.option || ""}</span>
                  <div>${data.data.content}</div>
                </div>
              </div>`;
            list.prepend(li);

            // ALSO update the paragraph's data-comments attribute so if you later rebuild it will contain this comment
            try {
              const existingAttr = p.getAttribute("data-comments");
              const arr = existingAttr ? JSON.parse(existingAttr) : [];
              arr.unshift({ author: data.data.author, avatar: data.data.avatar, option: data.data.option, text: data.data.content });
              p.setAttribute("data-comments", JSON.stringify(arr));
            } catch (err) {
              // ignore JSON errors
            }

            this.reset();
            popup.querySelector('[data-tab="list"]').click();
          } else {
            alert("Error: " + data.data.msg);
          }
        })
        .catch(err => {
          console.error("AJAX error", err);
          alert("Something went wrong. Try again.");
        })
        .finally(() => {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalText;
        });
      });

    }); // end forEach paragraphs
  } // end enableCommentsFeature

  // --- Check cookie on page load & set switch state
  const cookieValue = getCookie("ezd_selected_comment_switcher");
  if (cookieValue === "checked") {
    if (switcher) switcher.checked = true;
    enableCommentsFeature(); // initialize the comment feature immediately
    document.querySelectorAll(".doc-content-wrap p").forEach(pp => pp.classList.add("ezd_commentable_active"));
  }
  
  if (cookieValue != "checked") { 
    document.querySelectorAll(".doc-content-wrap p").forEach(pp => pp.classList.remove("ezd_commentable_active"));
  }

  // --- If user toggles the switcher (enable/disable) without reload
  if (switcher) {
    switcher.addEventListener("change", function () {
      if (this.checked) {
        setCookie("ezd_selected_comment_switcher", "checked", 30);
        // show existing elements (if created previously) or initialize
        document.querySelectorAll(".ezd_selected-comment-btn, .ezd_selected-popup")
          .forEach(el => el.style.display = "");
        enableCommentsFeature(); // safe, will only initialize the first time
        
        document.querySelectorAll(".doc-content-wrap p").forEach(pp => pp.classList.add("ezd_commentable_active"));

      } else {
        setCookie("ezd_selected_comment_switcher", "", -1);
        // hide UI but keep DOM so newly-added comments remain
        // also close any open popup and reset active markers
        if (activePopup) activePopup.classList.remove("active");
        if (activeBtn) activeBtn.classList.remove("active-btn");
        activePopup = null;
        activeBtn = null;

        
        document.querySelectorAll(".doc-content-wrap p").forEach(pp => pp.classList.remove("ezd_commentable_active"));

        document.querySelectorAll(".ezd_selected-comment-btn, .ezd_selected-popup")
          .forEach(el => el.style.display = "none");
      }
    });
  }
});