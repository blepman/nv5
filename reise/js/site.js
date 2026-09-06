(function () {
  var syncButton = document.getElementById("syncReise");
  if (syncButton) {
    syncButton.addEventListener("click", function () {
      var url = new URL(window.location.href);
      url.searchParams.set("sync", "1");
      window.location.replace(url.toString());
    });
  }

  var config = window.NV5_REISE;
  if (config && config.appVersion) {
    var footer = document.querySelector(".reise__footer");
    if (footer) {
      var note = document.createElement("span");
      note.className = "reise__version";
      note.textContent = "v" + config.appVersion;
      footer.appendChild(note);
    }
  }

  var bootUrl = new URL(window.location.href);
  if (bootUrl.searchParams.has("sync")) {
    bootUrl.searchParams.delete("sync");
    window.history.replaceState({}, "", bootUrl.toString());
  }
})();
