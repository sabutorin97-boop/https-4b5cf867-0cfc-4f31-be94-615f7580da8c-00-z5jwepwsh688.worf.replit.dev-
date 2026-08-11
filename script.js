/* ==========================================================================
   Окна Профигрупп — логика лендинга
   Квиз-подбор комплектации, форма заявки, мелкие эффекты страницы.
   ========================================================================== */

(function () {
  "use strict";

  /* ======================================================================
     НАСТРОЙКИ — всё, что нужно поменять перед запуском, находится здесь
     ====================================================================== */

  var SETTINGS = {
    // Куда отправлять заявки.
    // Вариант 1 — свой обработчик на хостинге:   "/send.php"
    // Вариант 2 — сторонний сервис (Formspree):  "https://formspree.io/f/ВАШ_КОД"
    // Пусто — заявка откроется письмом в почтовой программе клиента.
    FORM_ENDPOINT: "",

    COMPANY: "Окна Профигрупп",
    CONTACT_PHONE: "+7 000 000-00-00",
    CONTACT_EMAIL: "zakaz@okna-profigrupp.ru",
    TELEGRAM_URL: "https://t.me/sabutorin45"
  };

  /* ======================================================================
     ВОПРОСЫ КВИЗА
     ====================================================================== */

  var STEPS = [
    {
      id: "object",
      question: "Что будем остеклять?",
      options: [
        { value: "flat", label: "Квартира", icon: "i-window" },
        { value: "house", label: "Частный дом или дача", icon: "i-home" },
        { value: "balcony", label: "Балкон или лоджия", icon: "i-layers" },
        { value: "office", label: "Офис, магазин, помещение", icon: "i-award" }
      ]
    },
    {
      id: "count",
      question: "Сколько окон нужно поменять?",
      options: [
        { value: "1", label: "Одно окно" },
        { value: "2-3", label: "2–3 окна" },
        { value: "4-6", label: "4–6 окон" },
        { value: "7+", label: "Больше шести или весь дом" }
      ]
    },
    {
      id: "goals",
      question: "Что для вас важнее всего?",
      hint: "Можно выбрать несколько ответов",
      multiple: true,
      options: [
        { value: "noise", label: "Чтобы было тише — мешает шум с улицы", icon: "i-volume" },
        { value: "warm", label: "Чтобы было теплее — дует и холодно", icon: "i-thermometer" },
        { value: "light", label: "Больше света и открытый вид", icon: "i-sun" },
        { value: "safety", label: "Безопасность — дети или первый этаж", icon: "i-lock" },
        { value: "condensate", label: "Убрать конденсат и плесень на откосах", icon: "i-droplets" },
        { value: "old", label: "Просто заменить старые окна", icon: "i-window" }
      ]
    },
    {
      id: "street",
      question: "Куда выходят окна?",
      options: [
        { value: "yard", label: "Тихий двор" },
        { value: "street", label: "Обычная улица с движением" },
        { value: "highway", label: "Магистраль, железная дорога, аэропорт или стройка" }
      ]
    },
    {
      id: "sun",
      question: "Как в комнате с солнцем?",
      options: [
        { value: "hot", label: "Солнечная сторона, летом жарко" },
        { value: "dark", label: "Северная сторона, темновато" },
        { value: "mixed", label: "По-разному, не обращал внимания" }
      ]
    },
    {
      id: "when",
      question: "Когда планируете менять окна?",
      options: [
        { value: "now", label: "В ближайшие 1–2 недели" },
        { value: "month", label: "В течение месяца" },
        { value: "quarter", label: "Через 1–3 месяца" },
        { value: "later", label: "Пока прицениваюсь" }
      ]
    }
  ];

  /* ======================================================================
     ПОДБОР КОМПЛЕКТАЦИИ
     Чистая функция: по ответам возвращает описание решения.
     ====================================================================== */

  function pickSolution(a) {
    var goals = a.goals || [];
    var has = function (g) { return goals.indexOf(g) !== -1; };

    var noisy = a.street === "highway";
    var semiNoisy = a.street === "street";
    var balcony = a.object === "balcony";
    var house = a.object === "house";

    var why = [];

    /* --- Профиль ---
       58 мм — облегчённый: балкон и стандарт для квартиры;
       70 мм — комфорт, когда нужно теплее и тише обычного;
       80 мм — максимум тепла и защиты от шума, частный дом. */
    var profile, profileNote;

    // Особых пожеланий нет — человек просто меняет отслужившие окна
    var plainRequest = !has("warm") && !has("noise") && !has("light") &&
      !has("safety") && !has("condensate");

    if (balcony && !has("warm")) {
      profile = "Облегчённый профиль 58 мм";
      profileNote = "Балконная плита не рассчитана на тяжёлые конструкции, поэтому берём лёгкий профиль.";
    } else if (noisy || has("warm") || house) {
      profile = "Шестикамерный профиль 80 мм";
      profileNote = "Максимальная монтажная глубина — главный вклад и в тепло, и в защиту от шума.";
    } else if (plainRequest) {
      profile = "Профиль 58 мм";
      profileNote = "Стандартное решение для квартиры: для обычных городских условий этого достаточно, переплачивать не за что.";
    } else {
      profile = "Пятикамерный профиль 70 мм";
      profileNote = "Уровень комфорт: заметно теплее и тише стандартного профиля, без переплаты за лишние камеры.";
    }

    /* --- Стеклопакет --- */
    var glass, glassNote;
    if (has("noise") && noisy) {
      glass = "Шумозащитный двухкамерный с триплексом";
      glassNote = "Формула 6.4.1–14–4–14–4и: склеенное стекло гасит низкие частоты от трассы и поездов.";
      why.push("окна выходят на шумную сторону, а тишина у вас в приоритете — обычный стеклопакет тут не поможет");
    } else if (has("noise") && semiNoisy) {
      glass = "Двухкамерный с разной толщиной стёкол";
      glassNote = "Формула 6–14–4–14–4и: стёкла разной толщины гасят разные частоты, шум с улицы падает заметно.";
      why.push("на обычной городской улице разнотолщинный пакет убирает основной шум без доплаты за триплекс");
    } else if (has("light") && !has("warm")) {
      glass = "Двухкамерный с мультифункциональным стеклом";
      glassNote = "Пропускает максимум света, но отсекает жару летом и держит тепло зимой.";
      why.push("вам важен свет — поэтому берём прозрачное мультифункциональное стекло, а не тонировку");
    } else if (balcony && !has("warm")) {
      glass = "Однокамерный энергосберегающий";
      glassNote = "Для холодного балкона этого достаточно, конструкция остаётся лёгкой.";
    } else {
      glass = "Двухкамерный энергосберегающий";
      glassNote = "Формула 4–16–4–16–4и: напыление на внутреннем стекле возвращает тепло обратно в комнату.";
    }

    if (a.sun === "hot" && glass.indexOf("мультифункц") === -1) {
      glassNote += " Добавим мультифункциональное наружное стекло — на солнечной стороне летом будет прохладнее.";
      why.push("солнечная сторона: без солнцезащитного стекла комната перегревается уже к полудню");
    }

    /* --- Фурнитура --- */
    var hardware, hardwareNote;
    if (has("safety")) {
      hardware = "Противовзломная фурнитура класса RC2 с детским замком";
      hardwareNote = "Грибовидные цапфы не дают отжать створку, ручка с ключом не даст ребёнку открыть окно.";
      why.push("вы отметили безопасность — противовзломные цапфы и замок на ручке закрывают обе задачи сразу");
    } else {
      hardware = "Поворотно-откидная фурнитура с микропроветриванием";
      hardwareNote = "Створка фиксируется в режиме щелевого проветривания — свежий воздух без сквозняка.";
    }

    if (has("condensate") || has("warm")) {
      hardwareNote += " Ставим зимне-летний режим прижима, чтобы регулировать плотность по сезону.";
    }

    /* --- Дополнительно --- */
    var extras = ["Москитная сетка на открывающиеся створки"];

    if (has("condensate")) {
      extras.push("Приточный клапан — проветривание без открытых окон");
      extras.push("Тёплые откосы из сэндвич-панели вместо штукатурки");
      why.push("конденсат почти всегда идёт от нехватки притока воздуха, поэтому клапан здесь важнее дорогого стекла");
    } else if (has("warm")) {
      extras.push("Тёплая дистанционная рамка по контуру стеклопакета");
      why.push("холод чаще заходит по краю стеклопакета — тёплая рамка убирает этот мостик");
    }

    if (house) {
      extras.push("Усиленное армирование под большие створки");
    }
    if (a.count === "4-6" || a.count === "7+") {
      extras.push("Монтаж по этапам, чтобы не открывать весь дом сразу");
    }

    /* --- Заголовок по главной задаче --- */
    var titles = {
      noise: "Ваша задача — тишина",
      warm: "Ваша задача — тепло",
      safety: "Ваша задача — безопасность",
      condensate: "Ваша задача — сухие окна без плесени",
      light: "Ваша задача — свет и вид",
      old: "Ваша задача — заменить отслужившие окна"
    };
    var priority = ["noise", "warm", "safety", "condensate", "light", "old"];
    var main = null;
    for (var i = 0; i < priority.length; i++) {
      if (has(priority[i])) { main = priority[i]; break; }
    }

    if (!why.length) {
      why.push("для вашей ситуации хватает надёжной базовой комплектации — переплачивать за лишние опции незачем");
    }

    return {
      title: main ? titles[main] : "Мы подобрали комплектацию",
      subtitle: "Вот что решит её лучше всего",
      specs: [
        { icon: "i-layers", label: "Профиль", value: profile, note: profileNote },
        { icon: "i-window", label: "Стеклопакет", value: glass, note: glassNote },
        { icon: "i-lock", label: "Фурнитура", value: hardware, note: hardwareNote },
        { icon: "i-sparkles", label: "Дополнительно", value: extras.join(" · "), note: "" }
      ],
      why: why
    };
  }

  /* ======================================================================
     СОСТОЯНИЕ
     ====================================================================== */

  var STORAGE_KEY = "profigrupp-quiz";

  var state = {
    step: 0,          // 0..STEPS.length-1 — вопросы, STEPS.length — результат
    answers: {},
    sent: false
  };

  function saveState() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ step: state.step, answers: state.answers }));
    } catch (e) {
      /* приватный режим браузера — работаем без сохранения */
    }
  }

  function restoreState() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      var saved = JSON.parse(raw);
      if (saved && typeof saved.step === "number" && saved.answers) {
        state.answers = saved.answers;
        state.step = Math.min(saved.step, STEPS.length);
      }
    } catch (e) {
      /* повреждённые данные — начинаем с чистого листа */
    }
  }

  function resetState() {
    state = { step: 0, answers: {}, sent: false };
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
  }

  /* ======================================================================
     ХЕЛПЕРЫ
     ====================================================================== */

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
  }

  function icon(id, className) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    if (className) svg.setAttribute("class", className);
    svg.setAttribute("aria-hidden", "true");
    var use = document.createElementNS("http://www.w3.org/2000/svg", "use");
    use.setAttribute("href", "#" + id);
    svg.appendChild(use);
    return svg;
  }

  function labelOf(stepIndex, value) {
    var step = STEPS[stepIndex];
    for (var i = 0; i < step.options.length; i++) {
      if (step.options[i].value === value) return step.options[i].label;
    }
    return value;
  }

  /* Телефон приводим к виду +7 (999) 123-45-67 */
  function formatPhone(raw) {
    var digits = String(raw).replace(/\D/g, "");
    if (!digits) return "";
    if (digits[0] === "8" || digits[0] === "9") {
      digits = digits[0] === "8" ? "7" + digits.slice(1) : "7" + digits;
    }
    if (digits[0] !== "7") digits = "7" + digits;
    digits = digits.slice(0, 11);

    var out = "+7";
    if (digits.length > 1) out += " (" + digits.slice(1, 4);
    if (digits.length >= 5) out += ") " + digits.slice(4, 7);
    if (digits.length >= 8) out += "-" + digits.slice(7, 9);
    if (digits.length >= 10) out += "-" + digits.slice(9, 11);
    return out;
  }

  function phoneDigits(raw) {
    return String(raw).replace(/\D/g, "");
  }

  /* ======================================================================
     РЕНДЕР КВИЗА
     ====================================================================== */

  var shell = document.getElementById("quiz-shell");
  var body = document.getElementById("quiz-body");
  var progressWrap = document.getElementById("quiz-progress");
  var counterEl = document.getElementById("quiz-counter");
  var percentEl = document.getElementById("quiz-percent");
  var barEl = document.getElementById("quiz-bar");

  function updateProgress() {
    var total = STEPS.length + 1; // вопросы + экран контактов
    var current = Math.min(state.step + 1, total);
    var pct = Math.round((state.step / total) * 100);

    if (state.step >= STEPS.length) {
      counterEl.textContent = "Последний шаг — контакты";
      pct = 90;
    } else {
      counterEl.textContent = "Вопрос " + current + " из " + STEPS.length;
    }

    percentEl.textContent = pct + "%";
    barEl.style.width = pct + "%";
    barEl.setAttribute("aria-valuenow", String(pct));
  }

  function render() {
    if (!body) return;
    body.textContent = "";

    if (state.sent) {
      progressWrap.style.display = "none";
      renderSent();
      return;
    }

    progressWrap.style.display = "";
    updateProgress();

    if (state.step < STEPS.length) {
      renderQuestion(STEPS[state.step]);
    } else {
      renderResult();
    }
  }

  function renderQuestion(step) {
    var heading = el("h3", "quiz-question", step.question);
    heading.tabIndex = -1;
    body.appendChild(heading);

    if (step.hint) body.appendChild(el("p", "quiz-hint", step.hint));

    var list = el("div", "quiz-options");
    var selected = state.answers[step.id];

    // Кнопку «Далее» создаём заранее: обработчики вариантов управляют её доступностью
    var nextButton = el("button", "btn btn-primary");
    nextButton.type = "button";
    nextButton.textContent = "Далее";
    nextButton.addEventListener("click", goNext);

    step.options.forEach(function (option) {
      var isOn = step.multiple
        ? Array.isArray(selected) && selected.indexOf(option.value) !== -1
        : selected === option.value;

      var button = el("button", "quiz-option");
      button.type = "button";
      button.setAttribute("aria-pressed", isOn ? "true" : "false");

      var tick = el("span", "quiz-option-tick");
      tick.appendChild(icon("i-check"));
      button.appendChild(tick);
      button.appendChild(el("span", null, option.label));

      button.addEventListener("click", function () {
        if (step.multiple) {
          var current = Array.isArray(state.answers[step.id]) ? state.answers[step.id].slice() : [];
          var at = current.indexOf(option.value);
          if (at === -1) current.push(option.value); else current.splice(at, 1);
          state.answers[step.id] = current;
          saveState();
          button.setAttribute("aria-pressed", at === -1 ? "true" : "false");
          nextButton.disabled = current.length === 0;
        } else {
          state.answers[step.id] = option.value;
          saveState();
          list.querySelectorAll(".quiz-option").forEach(function (other) {
            other.setAttribute("aria-pressed", "false");
          });
          button.setAttribute("aria-pressed", "true");
          // Небольшая пауза, чтобы человек увидел, что вариант выбран
          window.setTimeout(goNext, 180);
        }
      });

      list.appendChild(button);
    });

    body.appendChild(list);

    var footer = el("div", "quiz-footer");

    var backButton = el("button", "btn btn-ghost");
    backButton.type = "button";
    backButton.appendChild(icon("i-arrow-left", "btn-icon"));
    backButton.appendChild(el("span", null, "Назад"));
    backButton.disabled = state.step === 0;
    backButton.addEventListener("click", goBack);
    footer.appendChild(backButton);

    if (step.multiple) {
      // Мультивыбор: без кнопки дальше не уйти
      nextButton.disabled = !Array.isArray(selected) || selected.length === 0;
      footer.appendChild(nextButton);
    } else if (selected) {
      // Одиночный выбор: кнопка нужна только тем, кто вернулся назад к готовому ответу
      footer.appendChild(nextButton);
    }

    body.appendChild(footer);
    heading.focus({ preventScroll: true });
  }

  function goNext() {
    if (state.step < STEPS.length) {
      state.step += 1;
      saveState();
      render();
    }
  }

  function goBack() {
    if (state.step > 0) {
      state.step -= 1;
      saveState();
      render();
    }
  }

  /* ---------------------- Результат подбора + форма --------------------- */

  function renderResult() {
    var solution = pickSolution(state.answers);

    var head = el("div", "result-head");
    var iconWrap = el("div", "result-icon bg-gradient-brand");
    iconWrap.appendChild(icon("i-sparkles"));
    head.appendChild(iconWrap);

    var title = el("h3", null, solution.title);
    title.tabIndex = -1;
    head.appendChild(title);
    head.appendChild(el("p", null, solution.subtitle));
    body.appendChild(head);

    var specs = el("ul", "spec-list");
    solution.specs.forEach(function (spec) {
      var item = el("li", "spec-item");
      var wrap = el("span", "spec-item-icon");
      wrap.appendChild(icon(spec.icon));
      item.appendChild(wrap);

      var textWrap = el("div");
      textWrap.appendChild(el("div", "spec-item-label", spec.label));
      textWrap.appendChild(el("div", "spec-item-value", spec.value));
      if (spec.note) textWrap.appendChild(el("div", "spec-item-note", spec.note));
      item.appendChild(textWrap);

      specs.appendChild(item);
    });
    body.appendChild(specs);

    var why = el("div", "result-why");
    why.appendChild(el("strong", null, "Почему именно так"));
    why.appendChild(el("span", null, solution.why.join("; ") + "."));
    body.appendChild(why);

    var recap = el("div", "answers-recap");
    STEPS.forEach(function (step, index) {
      var value = state.answers[step.id];
      if (!value) return;
      var values = Array.isArray(value) ? value : [value];
      values.forEach(function (v) {
        recap.appendChild(el("span", "chip", labelOf(index, v)));
      });
    });
    body.appendChild(recap);

    body.appendChild(buildForm(solution));
    title.focus({ preventScroll: true });
  }

  function buildForm(solution) {
    var form = el("form", "lead-form");
    form.noValidate = true;

    form.appendChild(el("p", "quiz-hint",
      "Оставьте контакты — инженер приедет на замер и уточнит комплектацию на месте. " +
      "Выезд по городу 500 ₽, при заключении договора вычитаем их из стоимости заказа."));

    var row = el("div", "field-row");

    /* Имя */
    var nameField = el("div", "field");
    var nameLabel = el("label", null, "Как к вам обращаться");
    nameLabel.htmlFor = "lead-name";
    var nameInput = el("input");
    nameInput.id = "lead-name";
    nameInput.name = "name";
    nameInput.type = "text";
    nameInput.autocomplete = "name";
    nameInput.placeholder = "Имя";
    nameField.appendChild(nameLabel);
    nameField.appendChild(nameInput);
    var nameError = el("div", "field-error");
    nameField.appendChild(nameError);
    row.appendChild(nameField);

    /* Телефон */
    var phoneField = el("div", "field");
    var phoneLabel = el("label", null, "Телефон");
    phoneLabel.htmlFor = "lead-phone";
    var phoneInput = el("input");
    phoneInput.id = "lead-phone";
    phoneInput.name = "phone";
    phoneInput.type = "tel";
    phoneInput.inputMode = "tel";
    phoneInput.autocomplete = "tel";
    phoneInput.placeholder = "+7 (___) ___-__-__";
    phoneInput.addEventListener("input", function (event) {
      var deleting = event.inputType && event.inputType.indexOf("delete") === 0;
      // Позволяем стереть поле полностью, иначе «+7» не даёт очистить ввод
      if (deleting && phoneDigits(phoneInput.value).length <= 1) {
        phoneInput.value = "";
        return;
      }
      var caretAtEnd = phoneInput.selectionStart === phoneInput.value.length;
      phoneInput.value = formatPhone(phoneInput.value);
      if (caretAtEnd) {
        phoneInput.setSelectionRange(phoneInput.value.length, phoneInput.value.length);
      }
    });
    phoneField.appendChild(phoneLabel);
    phoneField.appendChild(phoneInput);
    var phoneError = el("div", "field-error");
    phoneField.appendChild(phoneError);
    row.appendChild(phoneField);

    form.appendChild(row);

    /* Способ связи */
    var wayField = el("div", "field");
    var wayLabel = el("label", null, "Как удобнее связаться");
    wayLabel.htmlFor = "lead-way";
    var waySelect = el("select");
    waySelect.id = "lead-way";
    waySelect.name = "way";
    [
      "Позвонить",
      "Написать в WhatsApp",
      "Написать в Telegram",
      "Прислать смс — звонить неудобно"
    ].forEach(function (option) {
      var opt = el("option", null, option);
      opt.value = option;
      waySelect.appendChild(opt);
    });
    wayField.appendChild(wayLabel);
    wayField.appendChild(waySelect);
    form.appendChild(wayField);

    /* Ловушка для ботов */
    var honeypot = el("div", "hp-field");
    honeypot.setAttribute("aria-hidden", "true");
    var hpInput = el("input");
    hpInput.type = "text";
    hpInput.name = "company";
    hpInput.tabIndex = -1;
    hpInput.autocomplete = "off";
    honeypot.appendChild(hpInput);
    form.appendChild(honeypot);

    /* Согласие */
    var consent = el("label", "consent");
    var consentInput = el("input");
    consentInput.type = "checkbox";
    consentInput.checked = true;
    consent.appendChild(consentInput);
    var consentText = el("span");
    consentText.appendChild(document.createTextNode("Согласен на обработку персональных данных согласно "));
    var policyLink = el("a", null, "политике");
    policyLink.href = "privacy.html";
    policyLink.target = "_blank";
    policyLink.rel = "noopener";
    consentText.appendChild(policyLink);
    consent.appendChild(consentText);
    form.appendChild(consent);

    /* Отправка */
    var submitButton = el("button", "btn btn-primary btn-lg btn-block");
    submitButton.type = "submit";
    submitButton.appendChild(icon("i-ruler", "btn-icon"));
    submitButton.appendChild(el("span", null, "Записаться на замер"));
    form.appendChild(submitButton);

    var status = el("div", "form-status");
    status.setAttribute("role", "status");
    form.appendChild(status);

    var restart = el("button", "btn btn-ghost");
    restart.type = "button";
    restart.textContent = "Пройти подбор заново";
    restart.addEventListener("click", function () {
      resetState();
      render();
      shell.scrollIntoView({ behavior: "smooth", block: "start" });
    });
    form.appendChild(restart);

    form.addEventListener("submit", function (event) {
      event.preventDefault();

      // Бот заполнил скрытое поле — молча прекращаем
      if (hpInput.value) return;

      var ok = true;
      nameError.textContent = "";
      phoneError.textContent = "";
      nameField.removeAttribute("data-invalid");
      phoneField.removeAttribute("data-invalid");

      if (nameInput.value.trim().length < 2) {
        nameError.textContent = "Напишите, как к вам обращаться";
        nameField.setAttribute("data-invalid", "true");
        ok = false;
      }
      if (phoneDigits(phoneInput.value).length !== 11) {
        phoneError.textContent = "Введите телефон полностью";
        phoneField.setAttribute("data-invalid", "true");
        ok = false;
      }
      if (!consentInput.checked) {
        status.setAttribute("data-tone", "error");
        status.textContent = "Без согласия на обработку данных мы не сможем принять заявку";
        ok = false;
      }

      if (!ok) {
        (nameField.hasAttribute("data-invalid") ? nameInput : phoneInput).focus();
        return;
      }

      submitButton.disabled = true;
      status.setAttribute("data-tone", "");
      status.textContent = "Отправляем…";

      sendLead({
        name: nameInput.value.trim(),
        phone: formatPhone(phoneInput.value),
        way: waySelect.value,
        solution: solution,
        answers: state.answers
      }, function (result) {
        submitButton.disabled = false;
        if (result.ok) {
          state.sent = true;
          state.sentVia = result.via;
          render();
          shell.scrollIntoView({ behavior: "smooth", block: "start" });
        } else {
          status.setAttribute("data-tone", "error");
          status.textContent = "Не получилось отправить. Позвоните нам: " + SETTINGS.CONTACT_PHONE;
        }
      });
    });

    return form;
  }

  /* ---------------------------- Экран «готово» -------------------------- */

  function renderSent() {
    var wrap = el("div", "sent-state");

    var iconWrap = el("div", "result-icon");
    iconWrap.appendChild(icon("i-check"));
    wrap.appendChild(iconWrap);

    var title = el("h3", null, "Заявка принята");
    title.tabIndex = -1;
    wrap.appendChild(title);

    wrap.appendChild(el("p", null, state.sentVia === "mail"
      ? "Мы открыли письмо с вашими ответами — осталось нажать «Отправить» в почтовой программе. Или просто позвоните, так быстрее."
      : "Перезвоним в течение рабочего дня, согласуем удобное время замера. Инженер приедет со всеми образцами профиля."));

    var actions = el("div", "sent-actions");

    var call = el("a", "btn btn-primary");
    call.href = "tel:" + phoneDigitsToHref(SETTINGS.CONTACT_PHONE);
    call.appendChild(icon("i-phone", "btn-icon"));
    call.appendChild(el("span", null, "Позвонить сейчас"));
    actions.appendChild(call);

    if (SETTINGS.TELEGRAM_URL) {
      var tg = el("a", "btn btn-outline");
      tg.href = SETTINGS.TELEGRAM_URL;
      tg.target = "_blank";
      tg.rel = "noopener";
      tg.appendChild(icon("i-send", "btn-icon"));
      tg.appendChild(el("span", null, "Написать в Telegram"));
      actions.appendChild(tg);
    }

    wrap.appendChild(actions);

    var again = el("button", "btn btn-ghost");
    again.type = "button";
    again.textContent = "Подобрать окна ещё раз";
    again.addEventListener("click", function () {
      resetState();
      render();
    });
    wrap.appendChild(again);

    body.appendChild(wrap);
    title.focus({ preventScroll: true });
  }

  /* ======================================================================
     ОТПРАВКА ЗАЯВКИ
     ====================================================================== */

  function buildLeadText(lead) {
    var lines = [];
    lines.push("Заявка с сайта — подбор окон");
    lines.push("");
    lines.push("Имя: " + lead.name);
    lines.push("Телефон: " + lead.phone);
    lines.push("Способ связи: " + lead.way);
    lines.push("");
    lines.push("Ответы:");

    STEPS.forEach(function (step, index) {
      var value = lead.answers[step.id];
      if (!value) return;
      var values = Array.isArray(value) ? value : [value];
      var labels = values.map(function (v) { return labelOf(index, v); });
      lines.push("• " + step.question + " — " + labels.join(", "));
    });

    lines.push("");
    lines.push("Подобрано:");
    lead.solution.specs.forEach(function (spec) {
      lines.push("• " + spec.label + ": " + spec.value);
    });

    var source = collectSource();
    if (source) {
      lines.push("");
      lines.push("Источник: " + source);
    }

    return lines.join("\n");
  }

  function collectSource() {
    var parts = [];
    try {
      var params = new URLSearchParams(window.location.search);
      ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term"].forEach(function (key) {
        var value = params.get(key);
        if (value) parts.push(key + "=" + value);
      });
    } catch (e) {}
    if (document.referrer) parts.push("referrer=" + document.referrer);
    return parts.join(" ");
  }

  function phoneDigitsToHref(phone) {
    var digits = phoneDigits(phone);
    return digits ? "+" + digits : "";
  }

  function sendLead(lead, done) {
    var text = buildLeadText(lead);

    var payload = {
      name: lead.name,
      phone: lead.phone,
      way: lead.way,
      answers: lead.answers,
      solution: lead.solution.specs.map(function (s) { return s.label + ": " + s.value; }),
      source: collectSource(),
      page: window.location.href,
      text: text
    };

    if (!SETTINGS.FORM_ENDPOINT) {
      // Обработчик не настроен — отдаём заявку письмом
      var href = "mailto:" + SETTINGS.CONTACT_EMAIL +
        "?subject=" + encodeURIComponent("Заявка на замер — " + lead.name) +
        "&body=" + encodeURIComponent(text);
      window.location.href = href;
      done({ ok: true, via: "mail" });
      return;
    }

    fetch(SETTINGS.FORM_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        done({ ok: response.ok, via: "server" });
      })
      .catch(function () {
        done({ ok: false });
      });
  }

  /* ======================================================================
     ОФОРМЛЕНИЕ СТРАНИЦЫ
     ====================================================================== */

  function applySettings() {
    document.querySelectorAll('[data-role="phone-link"]').forEach(function (node) {
      node.href = "tel:" + phoneDigitsToHref(SETTINGS.CONTACT_PHONE);
      if (/\+?\d[\d\s()\-]{6,}/.test(node.textContent)) {
        node.textContent = SETTINGS.CONTACT_PHONE;
      }
    });
    document.querySelectorAll('[data-role="email-link"]').forEach(function (node) {
      node.href = "mailto:" + SETTINGS.CONTACT_EMAIL;
      node.textContent = SETTINGS.CONTACT_EMAIL;
    });
    var year = document.getElementById("year");
    if (year) year.textContent = String(new Date().getFullYear());
  }

  function setupReveal() {
    var items = document.querySelectorAll(".reveal");
    if (!("IntersectionObserver" in window)) {
      items.forEach(function (node) { node.classList.add("is-visible"); });
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: "0px 0px -10% 0px", threshold: 0.05 });

    items.forEach(function (node) { observer.observe(node); });
  }

  function setupStickyCta() {
    var sticky = document.getElementById("sticky-cta");
    var quiz = document.getElementById("quiz");
    if (!sticky || !quiz) return;

    window.addEventListener("scroll", function () {
      var quizBox = quiz.getBoundingClientRect();
      var scrolled = window.scrollY > window.innerHeight * 0.6;
      var quizOnScreen = quizBox.top < window.innerHeight && quizBox.bottom > 0;
      sticky.classList.toggle("is-visible", scrolled && !quizOnScreen);
    }, { passive: true });
  }

  /* ======================================================================
     СТАРТ
     ====================================================================== */

  applySettings();
  setupReveal();
  setupStickyCta();
  restoreState();
  render();
})();
