(function () {
  function readMeta(name) {
    var node = document.querySelector('meta[name="' + name + '"]');
    return node ? node.getAttribute("content") || "" : "";
  }

  function shaTail(value) {
    if (!value) {
      return "----";
    }
    return value.length <= 4 ? value : value.slice(-4);
  }

  function setText(id, value) {
    var node = document.getElementById(id);
    if (node) {
      node.textContent = value;
    }
  }

  function isLocalDev() {
    var host = window.location.hostname;
    return host === "localhost" || host === "127.0.0.1";
  }

  function forceSync(target) {
    var url = new URL(window.location.href);
    url.searchParams.set("sync", target);
    window.location.replace(url.toString());
  }

  function cleanSyncParam() {
    var url = new URL(window.location.href);
    if (!url.searchParams.has("sync")) {
      return;
    }
    url.searchParams.delete("sync");
    window.history.replaceState({}, "", url.toString());
  }

  setText("shaServer", shaTail(readMeta("nv5-server-sha")));
  setText("shaShared", shaTail(readMeta("nv5-shared-sha")));
  setText("shaSis", shaTail(readMeta("nv5-sis-sha")));
  setText("shaReise", shaTail(readMeta("nv5-reise-sha")));

  if (isLocalDev()) {
    var hint = document.getElementById("localHint");
    if (hint) {
      hint.hidden = false;
    }
  }

  document.querySelectorAll("[data-sync]").forEach(function (button) {
    button.addEventListener("click", function () {
      var target = button.getAttribute("data-sync");
      if (target) {
        forceSync(target);
      }
    });
  });

  cleanSyncParam();
})();
