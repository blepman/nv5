(function () {
  var syncButton = document.getElementById("syncReise");
  if (!syncButton) {
    return;
  }

  syncButton.addEventListener("click", function () {
    var url = new URL(window.location.href);
    url.searchParams.set("sync", "reise");
    window.location.replace(url.toString());
  });

  var bootUrl = new URL(window.location.href);
  if (bootUrl.searchParams.has("sync")) {
    bootUrl.searchParams.delete("sync");
    window.history.replaceState({}, "", bootUrl.toString());
  }
})();
