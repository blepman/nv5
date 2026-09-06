(function () {
  var config = window.NV5_REISE;
  if (!config) {
    console.error("Mangler config.js");
    return;
  }

  function escapeHtml(value) {
    if (window.NV5Util && typeof window.NV5Util.escapeHtml === "function") {
      return window.NV5Util.escapeHtml(value);
    }
    return String(value);
  }

  var els = {
    form: document.getElementById("tripForm"),
    fromSearch: document.getElementById("fromSearch"),
    toSearch: document.getElementById("toSearch"),
    fromResults: document.getElementById("fromResults"),
    toResults: document.getElementById("toResults"),
    tripStatus: document.getElementById("tripStatus"),
    tripResults: document.getElementById("tripResults"),
    swapStops: document.getElementById("swapStops"),
  };

  var state = {
    from: null,
    to: null,
    patterns: [],
    expandedIndex: -1,
    searchTimers: {},
  };

  function loadSettings() {
    try {
      var raw = localStorage.getItem(config.storageKey);
      if (!raw) {
        return {};
      }
      return JSON.parse(raw) || {};
    } catch (error) {
      return {};
    }
  }

  function saveSettings() {
    try {
      localStorage.setItem(
        config.storageKey,
        JSON.stringify({
          from: state.from,
          to: state.to,
        })
      );
    } catch (error) {
      console.warn("Kunne ikke lagre innstillinger", error);
    }
  }

  function modeLabel(mode) {
    return window.NV5Entur && typeof window.NV5Entur.modeLabel === "function"
      ? window.NV5Entur.modeLabel(mode)
      : mode || "";
  }

  function formatClock(date) {
    if (!date) {
      return "--:--";
    }
    return date.toLocaleTimeString("nb-NO", {
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  function formatDuration(seconds) {
    var total = Math.max(0, Math.round(Number(seconds) / 60));
    if (total < 60) {
      return total + " min";
    }
    var hours = Math.floor(total / 60);
    var mins = total % 60;
    return hours + " t " + mins + " min";
  }

  function formatWalkDistance(meters) {
    var m = Math.round(Number(meters) || 0);
    if (m < 1000) {
      return m + " m gange";
    }
    return (m / 1000).toFixed(1).replace(".", ",") + " km gange";
  }

  function setStatus(message, isError) {
    if (!els.tripStatus) {
      return;
    }
    if (!message) {
      els.tripStatus.hidden = true;
      els.tripStatus.textContent = "";
      els.tripStatus.classList.remove("is-error");
      return;
    }
    els.tripStatus.hidden = false;
    els.tripStatus.textContent = message;
    els.tripStatus.classList.toggle("is-error", Boolean(isError));
  }

  function clearResultsList(listEl) {
    if (listEl) {
      listEl.innerHTML = "";
      listEl.hidden = true;
    }
  }

  function hideAllResultLists() {
    clearResultsList(els.fromResults);
    clearResultsList(els.toResults);
  }

  async function runStopSearch(field, text) {
    var listEl = field === "from" ? els.fromResults : els.toResults;
    if (!listEl) {
      return;
    }
    var query = (text || "").trim();
    if (query.length < 2) {
      clearResultsList(listEl);
      return;
    }
    listEl.innerHTML = '<li class="reise__hint">Søker…</li>';
    listEl.hidden = false;
    try {
      var stops = await window.NV5Entur.searchStops(config, query);
      if (!stops.length) {
        listEl.innerHTML = '<li class="reise__hint">Ingen treff</li>';
        return;
      }
      listEl.innerHTML = stops
        .map(function (stop) {
          return (
            '<li><button type="button" class="reise__pick" data-field="' +
            escapeHtml(field) +
            '" data-id="' +
            escapeHtml(stop.id) +
            '" data-name="' +
            escapeHtml(stop.name) +
            '">' +
            escapeHtml(stop.label) +
            "</button></li>"
          );
        })
        .join("");
    } catch (error) {
      console.error(error);
      listEl.innerHTML = '<li class="reise__hint">Søk feilet</li>';
    }
  }

  function scheduleSearch(field, text) {
    clearTimeout(state.searchTimers[field]);
    state.searchTimers[field] = setTimeout(function () {
      runStopSearch(field, text);
    }, 300);
  }

  function pickStop(field, id, name) {
    var place = { id: id, name: name };
    if (field === "from") {
      state.from = place;
      els.fromSearch.value = name;
      clearResultsList(els.fromResults);
    } else {
      state.to = place;
      els.toSearch.value = name;
      clearResultsList(els.toResults);
    }
    saveSettings();
  }

  function legTitle(leg) {
    if (leg.mode === "foot") {
      return "Gå";
    }
    if (leg.lineCode) {
      return "Linje " + leg.lineCode;
    }
    return modeLabel(leg.transportMode || leg.mode);
  }

  function legHeadsign(leg) {
    if (leg.mode === "foot" || !leg.lineDestination) {
      return "";
    }
    return leg.lineDestination;
  }

  function legRoute(leg) {
    return leg.fromName + " → " + leg.toName;
  }

  function legModeUnderBadge(leg) {
    if (leg.mode === "foot") {
      return "Gange";
    }
    return modeLabel(leg.transportMode || leg.mode);
  }

  function lineStyleAttr(leg) {
    if (!leg.colour) {
      return "";
    }
    var fg = leg.textColour || "#fff";
    return (
      ' style="background:' +
      escapeHtml(leg.colour) +
      ";color:" +
      escapeHtml(fg) +
      ';"'
    );
  }

  function renderLegs(pattern) {
    return pattern.legs
      .map(function (leg) {
        var badge =
          leg.mode === "foot"
            ? '<span class="trip-leg__badge trip-leg__badge--walk">Gå</span>'
            : '<span class="trip-leg__badge"' +
              lineStyleAttr(leg) +
              ">" +
              escapeHtml(leg.lineCode || modeLabel(leg.transportMode)) +
              "</span>";
        var headsign = legHeadsign(leg);
        var headsignHtml = headsign
          ? '<span class="trip-leg__headsign">Endestopp ' +
            escapeHtml(headsign) +
            "</span>"
          : "";
        var situations = leg.situations.length
          ? '<p class="trip-leg__situation">' +
            escapeHtml(leg.situations.join(" ")) +
            "</p>"
          : "";
        return (
          '<li class="trip-leg">' +
          '<div class="trip-leg__aside">' +
          badge +
          '<span class="trip-leg__mode">' +
          escapeHtml(legModeUnderBadge(leg)) +
          "</span>" +
          "</div>" +
          '<div class="trip-leg__body">' +
          '<div class="trip-leg__top">' +
          "<strong>" +
          escapeHtml(legTitle(leg)) +
          "</strong>" +
          headsignHtml +
          '<time class="trip-leg__time">' +
          escapeHtml(formatClock(leg.startTime)) +
          "–" +
          escapeHtml(formatClock(leg.endTime)) +
          "</time>" +
          "</div>" +
          '<p class="trip-leg__route">' +
          escapeHtml(legRoute(leg)) +
          "</p>" +
          situations +
          "</div></li>"
        );
      })
      .join("");
  }

  function renderPatterns() {
    if (!els.tripResults) {
      return;
    }
    if (!state.patterns.length) {
      els.tripResults.innerHTML = "";
      return;
    }
    els.tripResults.innerHTML = state.patterns
      .map(function (pattern, index) {
        var expanded = state.expandedIndex === index;
        var changes =
          pattern.changes === 0
            ? "Ingen bytter"
            : pattern.changes === 1
              ? "1 bytte"
              : pattern.changes + " bytter";
        return (
          '<article class="trip-pattern' +
          (expanded ? " is-open" : "") +
          '">' +
          '<button type="button" class="trip-pattern__summary" data-pattern="' +
          index +
          '" aria-expanded="' +
          (expanded ? "true" : "false") +
          '">' +
          '<span class="trip-pattern__times">' +
          '<span class="trip-pattern__dep">' +
          escapeHtml(formatClock(pattern.startTime)) +
          "</span>" +
          '<span class="trip-pattern__arr">' +
          escapeHtml(formatClock(pattern.endTime)) +
          "</span>" +
          "</span>" +
          '<span class="trip-pattern__meta">' +
          "<strong>" +
          escapeHtml(formatDuration(pattern.duration)) +
          "</strong>" +
          "<span>" +
          escapeHtml(changes) +
          " · " +
          escapeHtml(formatWalkDistance(pattern.walkDistance)) +
          "</span>" +
          "</span>" +
          "</button>" +
          (expanded
            ? '<ol class="trip-legs">' + renderLegs(pattern) + "</ol>"
            : "") +
          "</article>"
        );
      })
      .join("");
  }

  async function searchTrips() {
    hideAllResultLists();
    if (!state.from || !state.from.id) {
      setStatus("Velg avreiseholdeplass", true);
      els.fromSearch.focus();
      return;
    }
    if (!state.to || !state.to.id) {
      setStatus("Velg destinasjon", true);
      els.toSearch.focus();
      return;
    }
    if (state.from.id === state.to.id) {
      setStatus("Fra og til kan ikke være samme sted", true);
      return;
    }

    setStatus("Søker reiser…", false);
    state.patterns = [];
    state.expandedIndex = -1;
    renderPatterns();

    try {
      var patterns = await window.NV5Entur.planTrip(
        config,
        state.from,
        state.to,
        new Date()
      );
      if (!patterns.length) {
        setStatus("Ingen reiseforslag funnet", true);
        return;
      }
      state.patterns = patterns;
      state.expandedIndex = 0;
      setStatus("");
      renderPatterns();
      saveSettings();
    } catch (error) {
      console.error(error);
      setStatus("Kunne ikke hente reiser. Prøv igjen.", true);
    }
  }

  function bindSearchField(field, inputEl) {
    if (!inputEl) {
      return;
    }
    inputEl.addEventListener("input", function () {
      var current =
        field === "from"
          ? state.from && state.from.name
          : state.to && state.to.name;
      if (inputEl.value.trim() !== (current || "").trim()) {
        if (field === "from") {
          state.from = null;
        } else {
          state.to = null;
        }
      }
      scheduleSearch(field, inputEl.value);
    });
    inputEl.addEventListener("focus", function () {
      if (inputEl.value.trim().length >= 2) {
        scheduleSearch(field, inputEl.value);
      }
    });
  }

  function bindResultList(listEl) {
    if (!listEl) {
      return;
    }
    listEl.addEventListener("click", function (event) {
      var btn = event.target.closest(".reise__pick");
      if (!btn) {
        return;
      }
      pickStop(
        btn.getAttribute("data-field"),
        btn.getAttribute("data-id"),
        btn.getAttribute("data-name")
      );
    });
  }

  function init() {
    var saved = loadSettings();
    state.from = saved.from || config.defaultFrom || null;
    state.to = saved.to || null;
    if (state.from && els.fromSearch) {
      els.fromSearch.value = state.from.name || "";
    }
    if (state.to && els.toSearch) {
      els.toSearch.value = state.to.name || "";
    }

    bindSearchField("from", els.fromSearch);
    bindSearchField("to", els.toSearch);
    bindResultList(els.fromResults);
    bindResultList(els.toResults);

    if (els.form) {
      els.form.addEventListener("submit", function (event) {
        event.preventDefault();
        searchTrips();
      });
    }

    if (els.swapStops) {
      els.swapStops.addEventListener("click", function () {
        var nextFrom = state.to;
        var nextTo = state.from;
        state.from = nextFrom;
        state.to = nextTo;
        if (els.fromSearch) {
          els.fromSearch.value = (state.from && state.from.name) || "";
        }
        if (els.toSearch) {
          els.toSearch.value = (state.to && state.to.name) || "";
        }
        hideAllResultLists();
        saveSettings();
      });
    }

    if (els.tripResults) {
      els.tripResults.addEventListener("click", function (event) {
        var btn = event.target.closest("[data-pattern]");
        if (!btn) {
          return;
        }
        var index = Number(btn.getAttribute("data-pattern"));
        state.expandedIndex = state.expandedIndex === index ? -1 : index;
        renderPatterns();
      });
    }

    document.addEventListener("click", function (event) {
      if (
        event.target.closest(".reise__search") ||
        event.target.closest(".reise__results")
      ) {
        return;
      }
      hideAllResultLists();
    });
  }

  init();
})();
