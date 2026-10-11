/* ==========================================================================
   Escola de Música Harmonia — comportamento de interface
   --------------------------------------------------------------------------
   Princípio: este ficheiro APENAS realça a interface. Todo o conteúdo,
   moradas, horários e preços estão no HTML e são legíveis sem JavaScript.
   O que aqui existe: menu mobile, separadores de cidade e construção da
   mensagem de contacto.
   ========================================================================== */

(function () {
  "use strict";

  /* Dados de contacto das três escolas.
     Números e emails são fictícios, para demonstração local. */
  var ESCOLAS = {
    lisboa: {
      nome: "Lisboa",
      email: "lisboa@harmonia.pt",
      telefone: "+351 21 000 0111",
      whatsapp: "351210000111"
    },
    porto: {
      nome: "Porto",
      email: "porto@harmonia.pt",
      telefone: "+351 22 000 0222",
      whatsapp: "351220000222"
    },
    beja: {
      nome: "Beja",
      email: "beja@harmonia.pt",
      telefone: "+351 26 000 0333",
      whatsapp: "351260000333"
    }
  };

  var EMAIL_GERAL = "geral@harmonia.pt";

  /* ------------------------------------------------------------------
     1. Menu de navegação em ecrãs pequenos
     ------------------------------------------------------------------ */
  function initNav() {
    var toggle = document.querySelector("[data-nav-toggle]");
    var nav = document.getElementById("site-nav");

    if (!toggle || !nav) return;

    function setOpen(open) {
      toggle.setAttribute("aria-expanded", String(open));
      nav.setAttribute("data-open", String(open));
      toggle.querySelector(".nav-toggle__label").textContent = open
        ? "Fechar"
        : "Menu";
    }

    function close() {
      setOpen(false);
    }

    toggle.addEventListener("click", function () {
      setOpen(toggle.getAttribute("aria-expanded") !== "true");
    });

    /* Escape fecha o menu e devolve o foco ao botão */
    document.addEventListener("keydown", function (ev) {
      if (
        ev.key === "Escape" &&
        toggle.getAttribute("aria-expanded") === "true"
      ) {
        close();
        toggle.focus();
      }
    });

    /* Clicar num link fecha o menu */
    nav.addEventListener("click", function (ev) {
      if (ev.target.closest("a")) close();
    });

    /* Clicar fora fecha o menu */
    document.addEventListener("click", function (ev) {
      if (
        toggle.getAttribute("aria-expanded") === "true" &&
        !nav.contains(ev.target) &&
        !toggle.contains(ev.target)
      ) {
        close();
      }
    });

    /* Ao passar para desktop, o menu volta a ser sempre visível */
    var desktop = window.matchMedia("(min-width: 56rem)");
    var onChange = function (ev) {
      if (ev.matches) close();
    };
    if (desktop.addEventListener) {
      desktop.addEventListener("change", onChange);
    } else {
      desktop.addListener(onChange);
    }

    setOpen(false);
  }

  /* ------------------------------------------------------------------
     2. Separadores de cidade (página Contactos)
     Padrão ARIA tablist completo: setas, Home/End, foco em roving tabindex.
     ------------------------------------------------------------------ */
  function initCityTabs() {
    var tablist = document.querySelector("[data-city-tabs]");
    if (!tablist) return;

    /* Ativação progressiva: só agora escondemos os painéis não ativos,
       para que sem JavaScript os trêscontinuem visíveis. */
    var tabs = Array.prototype.slice.call(
      tablist.querySelectorAll("[role='tab']")
    );
    var panels = tabs.map(function (tab) {
      return document.getElementById(tab.getAttribute("aria-controls"));
    });

    if (!tabs.length || panels.some(function (p) { return !p; })) return;

    tablist.removeAttribute("hidden");

    function select(index, moveFocus) {
      tabs.forEach(function (tab, i) {
        var active = i === index;
        tab.setAttribute("aria-selected", String(active));
        tab.setAttribute("tabindex", active ? "0" : "-1");
        panels[i].hidden = !active;
      });

      if (moveFocus) tabs[index].focus();

      /* Mantém a escolha atualizada no campo de cidade do formulário */
      var selectEscola = document.querySelector('[name="escola"]');
      if (selectEscola && tabs[index].dataset.cidade) {
        selectEscola.value = tabs[index].dataset.cidade;
      }
    }

    tabs.forEach(function (tab, i) {
      tab.addEventListener("click", function () {
        select(i, false);
      });

      tab.addEventListener("keydown", function (ev) {
        var next = null;

        switch (ev.key) {
          case "ArrowRight":
          case "ArrowDown":
            next = (i + 1) % tabs.length;
            break;
          case "ArrowLeft":
          case "ArrowUp":
            next = (i - 1 + tabs.length) % tabs.length;
            break;
          case "Home":
            next = 0;
            break;
          case "End":
            next = tabs.length - 1;
            break;
          default:
            return;
        }

        ev.preventDefault();
        select(next, true);
      });
    });

    select(0, false);
  }

  /* ------------------------------------------------------------------
     3. Ligações de WhatsApp
     Preenchidas com uma mensagem de contexto e com a cidade correta.
     ------------------------------------------------------------------ */
  function initWhatsApp() {
    var links = document.querySelectorAll("[data-whatsapp]");

    Array.prototype.forEach.call(links, function (link) {
      var cidade = link.getAttribute("data-whatsapp");
      var escola = ESCOLAS[cidade];

      if (!escola) return;

      var texto =
        "Olá! Gostaria de marcar uma aula experimental de música em " +
        escola.nome +
        ".";

      link.href =
        "https://wa.me/" +
        escola.whatsapp +
        "?text=" +
        encodeURIComponent(texto);
    });
  }

  /* ------------------------------------------------------------------
     4. Formulário de contacto → cliente de email
     Sem backend: compomos uma mensagem e abrimos o cliente de email do
     utilizador já preenchido. Verificação nativa do HTML corre antes.
     ------------------------------------------------------------------ */
  function initForm() {
    var form = document.getElementById("form-contacto");
    if (!form) return;

    var status = form.querySelector("[data-form-status]");

    form.addEventListener("submit", function (ev) {
      /* Deixa o navegador mostrar os erros de validação nativos */
      if (!form.checkValidity()) {
        ev.preventDefault();
        form.reportValidity();
        return;
      }

      ev.preventDefault();

      var dados = new FormData(form);
      var cidade = dados.get("escola");
      var escola = ESCOLAS[cidade] || { nome: "escola a escolher" };

      var assunto =
        "Aula experimental — " +
        escola.nome +
        " — " +
        (dados.get("nome") || "");

      var corpo = [
        "Nome: " + (dados.get("nome") || ""),
        "Email: " + (dados.get("email") || ""),
        "Telefone: " + (dados.get("telefone") || "—"),
        "Escola: " + escola.nome,
        "Instrumento: " + (dados.get("instrumento") || "—"),
        "Nível: " + (dados.get("nivel") || "—"),
        "",
        "Mensagem:",
        dados.get("mensagem") || "—"
      ].join("\n");

      var href =
        "mailto:" +
        escola.email +
        "?subject=" +
        encodeURIComponent(assunto) +
        "&body=" +
        encodeURIComponent(corpo);

      if (status) {
        status.textContent =
          "Abrimos o seu programa de email com a mensagem preenchida, " +
          "endereçada a " +
          escola.email +
          ". Se nada abrir, escreva diretamente para esse endereço.";
      }

      window.location.href = href;
    });
  }

  /* ------------------------------------------------------------------
     5. Ligações mailto estáticas com contexto
     ------------------------------------------------------------------ */
  function initMailLinks() {
    var links = document.querySelectorAll("[data-mailto-escola]");

    Array.prototype.forEach.call(links, function (link) {
      var escola = ESCOLAS[link.getAttribute("data-mailto-escola")];
      if (escola) link.href = "mailto:" + escola.email;
    });
  }

  /* ------------------------------------------------------------------
     Arranque
     ------------------------------------------------------------------ */
  function init() {
    initNav();
    initCityTabs();
    initWhatsApp();
    initForm();
    initMailLinks();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();