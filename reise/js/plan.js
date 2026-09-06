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
    tripSubmit: document.getElementById("tripSubmit"),
    tripDateTimeWrap: document.getElementById("tripDateTimeWrap"),
    tripDate: document.getElementById("tripDate"),
    tripTime: document.getElementById("tripTime"),
    whenButtons: document.querySelectorAll(".reise__when-btn"),
  };

  var state = {
    from: null,
    to: null,
    patterns: [],
    expandedIndex: -1,
    searchTimers: {},
    when: "now",
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
          when: state.when,
          departDate: els.tripDate ? els.tripDate.value : "",
          departTime: els.tripTime ? els.tripTime.value : "",
        })
      );
    } catch (error) {
      console.warn("Kunne ikke lagre innstillinger", error);
    }
  }

  function pad2(value) {
    return String(value).padStart(2, "0");
  }

  function formatDateInputValue(date) {
    return (
      date.getFullYear() +
      "-" +
      pad2(date.getMonth() + 1) +
      "-" +
      pad2(date.getDate())
    );
  }

  function formatTimeInputValue(date) {
    return pad2(date.getHours()) + ":" + pad2(date.getMinutes());
  }

  function defaultDepartDateTime() {
    var d = new Date();
    d.setSeconds(0, 0);
    var mins = d.getMinutes();
    var rounded = Math.ceil(mins / 5) * 5;
    if (rounded >= 60) {
      d.setHours(d.getHours() + 1);
      d.setMinutes(0);
    } else {
      d.setMinutes(rounded);
    }
    return d;
  }

  function setDepartInputs(date) {
    if (!els.tripDate || !els.tripTime) {
      return;
    }
    els.tripDate.value = formatDateInputValue(date);
    els.tripTime.value = formatTimeInputValue(date);
  }

  function updateWhenUi() {
    var isDepart = state.when === "depart";
    if (els.tripDateTimeWrap) {
      els.tripDateTimeWrap.hidden = !isDepart;
    }
    if (els.whenButtons) {
      els.whenButtons.forEach(function (btn) {
        var active = btn.getAttribute("data-when") === state.when;
        btn.classList.toggle("is-active", active);
        btn.setAttribute("aria-pressed", active ? "true" : "false");
      });
    }
    if (els.tripSubmit) {
      els.tripSubmit.textContent = isDepart ? "Søk reise" : "Reis nå";
    }
  }

  function setWhen(mode) {
    state.when = mode === "depart" ? "depart" : "now";
    if (state.when === "depart") {
      if (els.tripDate && !els.tripDate.value) {
        setDepartInputs(defaultDepartDateTime());
      }
    }
    updateWhenUi();
    saveSettings();
  }

  function getDepartureDateTime() {
    if (state.when !== "depart") {
      return new Date();
    }
    if (!els.tripDate || !els.tripTime) {
      return new Date();
    }
    var datePart = els.tripDate.value;
    var timePart = els.tripTime.value;
    if (!datePart || !timePart) {
      return null;
    }
    var parts = datePart.split("-").map(Number);
    var timeParts = timePart.split(":").map(Number);
    if (parts.length < 3 || timeParts.length < 2) {
      return null;
    }
    return new Date(parts[0], parts[1] - 1, parts[2], timeParts[0], timeParts[1], 0, 0);
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

  function legGapSeconds(legs, index) {
    if (!legs || index <= 0) {
      return 0;
    }
    var previous = legs[index - 1];
    var current = legs[index];
    if (!previous.endTime || !current.startTime) {
      return 0;
    }
    return Math.max(
      0,
      Math.round((current.startTime.getTime() - previous.endTime.getTime()) / 1000)
    );
  }

  function changesLabel(changes) {
    if (changes === 0) {
      return "Ingen bytter";
    }
    if (changes === 1) {
      return "1 bytte";
    }
    return changes + " bytter";
  }

  function patternMetaParts(pattern) {
    var parts = [];
    if (pattern.changes <= 2) {
      parts.push(changesLabel(pattern.changes));
    }
    if (pattern.walkDuration >= 60) {
      parts.push(formatDuration(pattern.walkDuration) + " gange");
    } else if (pattern.walkDistance > 0) {
      parts.push(formatWalkDistance(pattern.walkDistance));
    }
    if (pattern.transferDuration >= 60) {
      parts.push(formatDuration(pattern.transferDuration) + " overgang");
    }
    return parts;
  }

  function renderMetaPills(pattern) {
    return patternMetaParts(pattern)
      .map(function (text) {
        return '<span class="trip-meta-pill">' + escapeHtml(text) + "</span>";
      })
      .join("");
  }

  function transitLegs(pattern) {
    return (pattern.legs || []).filter(function (leg) {
      return leg.mode !== "foot";
    });
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
      if (leg.duration >= 60) {
        return "Gå · " + formatDuration(leg.duration);
      }
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

  function quayPlatformLabel(placeName, quayCode) {
    if (!quayCode) {
      return "";
    }
    return (placeName || "Holdeplass") + " pltf. " + quayCode;
  }

  function legStopLabel(name, quayCode) {
    return quayPlatformLabel(name, quayCode) || name || "";
  }

  function renderRouteBadge(leg) {
    var code = leg.lineCode || modeLabel(leg.transportMode || leg.mode);
    return (
      '<span class="trip-route-tag__badge"' +
      lineStyleAttr(leg) +
      ">" +
      escapeHtml(code) +
      "</span>"
    );
  }

  function renderRouteTag(leg) {
    var dest = String(leg.lineDestination || "").trim();
    return (
      '<span class="trip-route-tag">' +
      renderRouteBadge(leg) +
      (dest
        ? '<span class="trip-route-tag__dest">' + escapeHtml(dest) + "</span>"
        : "") +
      "</span>"
    );
  }

  function renderPatternRoute(pattern) {
    var transit = transitLegs(pattern);
    if (pattern.changes > 2) {
      var first = transit[0];
      var last = transit.length > 1 ? transit[transit.length - 1] : null;
      var parts = [];
      if (first) {
        parts.push('<span class="trip-route-tag">' + renderRouteBadge(first) + "</span>");
      }
      parts.push(
        '<span class="trip-card__route-compact">' +
          escapeHtml(changesLabel(pattern.changes)) +
          "</span>"
      );
      if (last && last !== first) {
        parts.push('<span class="trip-route-tag">' + renderRouteBadge(last) + "</span>");
      }
      return (
        '<div class="trip-card__route-tags trip-card__route-tags--compact">' +
        parts.join('<span class="trip-route-sep" aria-hidden="true">→</span>') +
        "</div>"
      );
    }
    if (!transit.length) {
      return "";
    }
    var tags = [];
    transit.forEach(function (leg, index) {
      if (index > 0) {
        tags.push('<span class="trip-route-sep" aria-hidden="true">→</span>');
      }
      tags.push(renderRouteTag(leg));
    });
    return '<div class="trip-card__route-tags">' + tags.join("") + "</div>";
  }

  function legRoute(leg) {
    if (leg.mode === "foot" && leg.fromName && leg.fromName === leg.toName) {
      if (leg.fromQuay && leg.toQuay) {
        var fromPlatform = quayPlatformLabel(leg.fromName, leg.fromQuay);
        var toPlatform = quayPlatformLabel(leg.toName, leg.toQuay);
        if (fromPlatform && toPlatform && fromPlatform !== toPlatform) {
          return fromPlatform + " – " + toPlatform;
        }
      }
      if (
        leg.fromQuayDescription &&
        leg.toQuayDescription &&
        leg.fromQuayDescription !== leg.toQuayDescription
      ) {
        return leg.fromQuayDescription + " → " + leg.toQuayDescription;
      }
    }
    return (
      legStopLabel(leg.fromName, leg.fromQuay) +
      " → " +
      legStopLabel(leg.toName, leg.toQuay)
    );
  }

  function legBadgeText(leg) {
    return leg.lineCode || modeLabel(leg.transportMode || leg.mode);
  }

  function legBadgeClass(leg) {
    var text = String(legBadgeText(leg) || "");
    if (text.length > 3) {
      return " trip-timeline__marker--compact";
    }
    return "";
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

  function renderTimelineLeg(leg) {
    var isWalk = leg.mode === "foot";
    var markerClass =
      "trip-timeline__marker" +
      (isWalk ? " trip-timeline__marker--walk" : "") +
      legBadgeClass(leg);
    var markerContent = isWalk
      ? "Gå"
      : escapeHtml(legBadgeText(leg));
    var markerStyle = isWalk ? "" : lineStyleAttr(leg);
    var headsign = legHeadsign(leg);
    var headsignHtml = headsign
      ? '<span class="trip-timeline__headsign">' + escapeHtml(headsign) + "</span>"
      : "";
    var situations = leg.situations.length
      ? '<p class="trip-timeline__situation">' +
        escapeHtml(leg.situations.join(" ")) +
        "</p>"
      : "";
    var occupancy =
      !isWalk && leg.occupancyLabel
        ? '<p class="trip-timeline__occupancy">' +
          escapeHtml(leg.occupancyLabel) +
          "</p>"
        : "";
    return (
      '<li class="trip-timeline__item' +
      (isWalk ? " trip-timeline__item--walk" : "") +
      '">' +
      '<div class="trip-timeline__track" aria-hidden="true">' +
      '<span class="' +
      markerClass +
      '"' +
      markerStyle +
      ">" +
      markerContent +
      "</span>" +
      "</div>" +
      '<div class="trip-timeline__panel">' +
      '<div class="trip-timeline__header">' +
      '<div class="trip-timeline__title">' +
      "<strong>" +
      escapeHtml(legTitle(leg)) +
      "</strong>" +
      headsignHtml +
      "</div>" +
      '<time class="trip-timeline__time">' +
      escapeHtml(formatClock(leg.startTime)) +
      "–" +
      escapeHtml(formatClock(leg.endTime)) +
      "</time>" +
      "</div>" +
      '<p class="trip-timeline__route">' +
      escapeHtml(legRoute(leg)) +
      "</p>" +
      occupancy +
      situations +
      "</div></li>"
    );
  }

  function renderLegs(pattern) {
    var html = [];
    (pattern.legs || []).forEach(function (leg, index) {
      if (index > 0) {
        var gap = legGapSeconds(pattern.legs, index);
        if (gap >= 60) {
          html.push(
            '<li class="trip-timeline__gap">' +
              '<div class="trip-timeline__track" aria-hidden="true"></div>' +
              '<span class="trip-timeline__gap-label">' +
              escapeHtml(formatDuration(gap) + " overgang") +
              "</span></li>"
          );
        }
      }
      html.push(renderTimelineLeg(leg));
    });
    return html.join("");
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
        return (
          '<article class="trip-card' +
          (expanded ? " is-open" : "") +
          '">' +
          '<button type="button" class="trip-card__header" data-pattern="' +
          index +
          '" aria-expanded="' +
          (expanded ? "true" : "false") +
          '">' +
          '<div class="trip-card__schedule">' +
          '<time class="trip-card__dep" datetime="' +
          escapeHtml(pattern.startTime ? pattern.startTime.toISOString() : "") +
          '">' +
          escapeHtml(formatClock(pattern.startTime)) +
          "</time>" +
          '<span class="trip-card__schedule-bar" aria-hidden="true"></span>' +
          '<time class="trip-card__arr" datetime="' +
          escapeHtml(pattern.endTime ? pattern.endTime.toISOString() : "") +
          '">' +
          escapeHtml(formatClock(pattern.endTime)) +
          "</time>" +
          '<span class="trip-card__duration">' +
          escapeHtml(formatDuration(pattern.duration)) +
          "</span>" +
          "</div>" +
          '<div class="trip-card__main">' +
          renderPatternRoute(pattern) +
          '<div class="trip-card__meta">' +
          renderMetaPills(pattern) +
          "</div>" +
          "</div>" +
          '<span class="trip-card__chevron" aria-hidden="true">›</span>' +
          "</button>" +
          (expanded
            ? '<div class="trip-card__details"><ol class="trip-timeline">' +
              renderLegs(pattern) +
              "</ol></div>"
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

    var departAt = getDepartureDateTime();
    if (departAt === null) {
      setStatus("Velg dato og tidspunkt", true);
      if (els.tripDate) {
        els.tripDate.focus();
      }
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
        departAt
      );
      if (!patterns.length) {
        setStatus("Ingen reiseforslag funnet", true);
        return;
      }
      state.patterns = patterns;
      state.expandedIndex = -1;
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
    state.when = saved.when === "depart" ? "depart" : "now";
    if (state.when === "depart" && saved.departDate && saved.departTime) {
      if (els.tripDate) {
        els.tripDate.value = saved.departDate;
      }
      if (els.tripTime) {
        els.tripTime.value = saved.departTime;
      }
    }
    updateWhenUi();
    if (els.tripDate) {
      els.tripDate.min = formatDateInputValue(new Date());
    }
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

    if (els.whenButtons && els.whenButtons.length) {
      els.whenButtons.forEach(function (btn) {
        btn.addEventListener("click", function () {
          setWhen(btn.getAttribute("data-when"));
        });
      });
    }

    if (els.tripDate) {
      els.tripDate.addEventListener("change", saveSettings);
    }
    if (els.tripTime) {
      els.tripTime.addEventListener("change", saveSettings);
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
