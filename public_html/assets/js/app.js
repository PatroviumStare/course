(() => {
  const page = document.body.dataset.page;
  const isFilePreview = window.location.protocol === "file:";
  const apiRoot = "/api";
  let csrfToken = "";
  let currentUser = null;
  let currentRating = 0;
  let serverTimer = null;
  let dashboardArticles = [];
  let dashboardUsers = [];
  let publicationsExpanded = false;
  let usersExpanded = false;

  const cacheRead = (key) => {
    try {
      return JSON.parse(sessionStorage.getItem(`digest-${key}`) || "null");
    } catch {
      return null;
    }
  };
  const cacheWrite = (key, value) => {
    try {
      sessionStorage.setItem(`digest-${key}`, JSON.stringify(value));
    } catch {
    }
  };

  const fileUrl = (path) => {
    if (!isFilePreview) return path;
    const depth =
      window.location.pathname.includes("/article/") ||
      window.location.pathname.includes("/tag/") ||
      /\/(login|register|dashboard|editor|profile|subscriptions|settings)\//.test(
        window.location.pathname,
      )
        ? "../"
        : "";
    return `${depth}${path.replace(/^\//, "")}`;
  };

  async function api(path, options = {}) {
    if (isFilePreview)
      throw new Error(
        "ops",
      );
    const response = await fetch(`${apiRoot}${path}`, {
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        ...(options.headers || {}),
      },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      if (response.status === 401) clearAccountCache();
      throw new Error(data.error || `Ошибка запроса (${response.status}).`);
    }
    return data;
  }

  function clearAccountCache() {
    try {
      for (const key of ["user", "dashboard", "settings", "subscriptions"]) {
        sessionStorage.removeItem(`digest-${key}`);
      }
    } catch {
    }
  }

  function showNotice(message, type = "success") {
    const notice = document.querySelector("#notice");
    if (!notice) return;
    notice.textContent = message;
    notice.className = `flash-message ${type}`;
    notice.hidden = false;
    window.setTimeout(() => {
      notice.hidden = true;
    }, 5200);
  }

  function textElement(tag, value, className) {
    const node = document.createElement(tag);
    node.textContent = value ?? "";
    if (className) node.className = className;
    return node;
  }

  function renderAccountActions(user) {
    const slot = document.querySelector("#account-actions");
    if (!slot) return;
    slot.className = "account-actions";
    if (!user) {
      slot.replaceChildren(
        Object.assign(document.createElement("a"), {
          href: fileUrl("/login"),
          textContent: "Войти",
          className: "text-button",
        }),
        Object.assign(document.createElement("a"), {
          href: fileUrl("/register"),
          textContent: "Регистрация",
          className: "primary-button compact",
        }),
      );
      return;
    }
    const menu = document.createElement("div");
    menu.className = "account-menu";
    const trigger = document.createElement("button");
    trigger.className = "account-trigger";
    trigger.type = "button";
    const avatar = document.createElement("img");
    avatar.src =
      user.avatar_url || fileUrl("/assets/images/avatar-default.svg");
    avatar.alt = "";
    trigger.append(avatar, textElement("span", user.name, `role-${user.role || "viewer"}`));
    trigger.setAttribute("aria-expanded", "false");
    trigger.addEventListener("click", () => {
      const open = menu.classList.toggle("is-open");
      trigger.setAttribute("aria-expanded", String(open));
    });
    const dropdown = document.createElement("div");
    dropdown.className = "account-dropdown";
    const profile = document.createElement("a");
    profile.href = `${fileUrl("/profile")}?id=${user.id}`;
    profile.textContent = "Профиль";
    const settings = document.createElement("a");
    settings.href = fileUrl("/settings");
    settings.textContent = "Настройки";
    const dashboardLink = document.createElement("a");
    dashboardLink.href = fileUrl("/dashboard");
    dashboardLink.textContent = "Панель управления";
    dropdown.append(profile, settings, dashboardLink);
    if (["author", "admin"].includes(user.role)) {
      const editor = document.createElement("a");
      editor.href = fileUrl("/editor");
      editor.textContent = "Написать статью";
      dropdown.append(editor);
    }
    const switchButton = textElement("button", "Сменить аккаунт");
    switchButton.type = "button";
    switchButton.addEventListener("click", openAccountSwitcher);
    const logout = textElement("button", "Выйти из аккаунта");
    logout.type = "button";
    logout.addEventListener("click", logoutUser);
    dropdown.append(switchButton, logout);
    menu.append(trigger, dropdown);
    const subscriptions = document.createElement("a");
    subscriptions.className = "account-subscriptions text-button";
    subscriptions.href = fileUrl("/subscriptions");
    subscriptions.textContent = "Подписки";
    const controls = [subscriptions, menu];
    if (["author", "admin"].includes(user.role)) {
      const compose = document.createElement("a");
      compose.className = "icon-button compose-link";
      compose.href = fileUrl("/editor");
      compose.setAttribute("aria-label", "Создать публикацию");
      compose.title = "Создать публикацию";
      compose.textContent = "+";
      controls.push(compose);
    }
    slot.replaceChildren(...controls);
  }

  async function openAccountSwitcher() {
    try {
      const result = await api("/account?view=switch-accounts");
      const modal = createModal("Сменить аккаунт");
      const list = document.createElement("div");
      list.className = "switch-account-list";
      for (const account of result.accounts) {
        const button = document.createElement("button");
        button.className = "switch-account-item";
        button.type = "button";
        const image = document.createElement("img");
        image.src =
          account.avatar_url || fileUrl("/assets/images/avatar-default.svg");
        image.alt = "";
        button.append(
          image,
          textElement("span", account.name),
          textElement("small", account.email),
        );
        button.addEventListener("click", async () => {
          try {
            const switched = await api("/account", {
              method: "POST",
              body: JSON.stringify({
                action: "switch-account",
                switch_token: account.switch_token,
                csrf: csrfToken,
              }),
            });
            clearAccountCache();
            cacheWrite("user", switched.user);
            window.location.reload();
          } catch (error) {
            showNotice(error.message, "error");
          }
        });
        list.append(button);
      }
      if (!result.accounts.length)
        list.append(
          textElement(
            "p",
            "На этом устройстве нет сохранённых аккаунтов.",
            "metric",
          ),
        );
      const add = document.createElement("a");
      add.className = "outline-button";
    add.href = fileUrl("/login?add-account=1");
      add.textContent = "+ Добавить аккаунт";
      modal.panel.append(list, add);
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  function createModal(title) {
    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop";
    const panel = document.createElement("section");
    panel.className = "modal-panel";
    const heading = document.createElement("h2");
    heading.textContent = title;
    const close = textElement("button", "Закрыть", "outline-button");
    close.type = "button";
    close.addEventListener("click", () => backdrop.remove());
    const actions = document.createElement("div");
    actions.className = "modal-actions";
    actions.append(close);
    panel.append(heading, actions);
    backdrop.addEventListener("click", (event) => {
      if (event.target === backdrop) backdrop.remove();
    });
    backdrop.append(panel);
    document.body.append(backdrop);
    return { backdrop, panel, body: panel };
  }

  function articleCard(article) {
    const row = document.createElement("article");
    row.className = "article-row";
    const copy = document.createElement("div");
    copy.className = "article-copy";
    const meta = document.createElement("div");
    meta.className = "article-meta";
    const category = document.createElement("a");
    category.href = `${fileUrl("/")}?category=${encodeURIComponent(article.category_slug || "")}`;
    category.textContent =
      article.category || article.category_name || "Новости";
    const date = document.createElement("span");
    date.textContent = article.published_at
      ? new Date(
          `${article.published_at.replace(" ", "T")}Z`,
        ).toLocaleDateString("ru-RU")
      : "";
    meta.append(category, date);
    const title = document.createElement("h3");
    const link = document.createElement("a");
    link.href = isFilePreview
      ? `${fileUrl("/article/index.html")}?slug=${encodeURIComponent(article.slug)}`
      : `/article/${encodeURIComponent(article.slug)}`;
    link.textContent = article.title;
    title.append(link);
    const byline = document.createElement("div");
    byline.className = "article-byline";
    const author = document.createElement("a");
    const authorId = article.author_id || article.author_user_id;
    author.href = authorId ? `${fileUrl("/profile")}?id=${authorId}` : "#";
    author.textContent = article.author || article.author_name || "Редакция";
    if (article.author_role === "moderator" || article.author_role === "admin")
      author.classList.add(`role-${article.author_role}`);
    byline.append(
      author,
      textElement("span", "·"),
      textElement(
        "span",
        `${Number(article.views || 0).toLocaleString("ru-RU")} просмотров`,
      ),
    );
    if (article.author_tags) {
      const tags = document.createElement("span"); tags.className = "author-profile-tags";
      article.author_tags.split(",").filter(Boolean).forEach((tag) => tags.append(textElement("span", tag, "tag-chip")));
      byline.append(tags);
    }
    const metrics = document.createElement("div");
    metrics.className = "card-metrics";
    metrics.append(
      textElement(
        "span",
        `★ ${article.rating_average || "—"} (${Number(article.rating_count || 0)})`,
        "card-metric",
      ),
      textElement(
        "span",
        `◌ ${Number(article.comment_count || 0)}`,
        "card-metric",
      ),
    );
    copy.append(
      meta,
      title,
      textElement("p", article.subtitle),
      byline,
      metrics,
    );
    row.append(copy);
    if (article.image_url) {
      const thumb = document.createElement("a");
      thumb.className = "article-thumb";
      thumb.href = link.href;
      const image = document.createElement("img");
      image.src = article.image_url;
      image.alt = "";
      image.loading = "lazy";
      image.addEventListener("error", () => image.remove(), { once: true });
      thumb.append(image);
      row.append(thumb);
    }
    return row;
  }

  function actionButton(label, action, data = {}, style = "") {
    const form = document.createElement("form");
    form.className = "small-form";
    const button = textElement("button", label, `small-button ${style}`);
    button.type = "submit";
    form.append(button);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (
        action === "delete-article" &&
        !window.confirm("Удалить статью без возможности восстановления?")
      )
        return;
      try {
        const endpoint = [
          "unban-user",
          "ban-user",
          "resolve-banned-content",
        ].includes(action)
          ? "/account"
          : "/actions";
        await api(endpoint, {
          method: "POST",
          body: JSON.stringify({ action, ...data, csrf: csrfToken }),
        });
        showNotice("Изменения сохранены.");
        if (page === "dashboard") loadDashboard();
        if (page === "article") loadArticle();
      } catch (error) {
        showNotice(error.message, "error");
      }
    });
    return form;
  }

  async function loadIdentity() {
    try {
      const state = await api("/auth");
      csrfToken = state.csrf;
      const cachedUser = cacheRead("user");
      if (cachedUser?.id && state.user?.id && Number(cachedUser.id) !== Number(state.user.id)) {
        clearAccountCache();
      }
      currentUser = state.user;
      if (!currentUser) clearAccountCache();
      cacheWrite("user", currentUser);
      renderAccountActions(currentUser);
      return state;
    } catch (error) {
      if (!isFilePreview) showNotice(error.message, "error");
      return null;
    }
  }

  async function logoutUser() {
    try {
      await api("/auth", {
        method: "POST",
        body: JSON.stringify({ action: "logout", csrf: csrfToken }),
      });
      clearAccountCache();
      window.location.href = fileUrl("/");
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  async function loadRandomArticle() {
    try {
      const result = await api("/random-article");
      window.location.href = isFilePreview
        ? `${fileUrl("/article/index.html")}?slug=${encodeURIComponent(result.slug)}`
        : `/article/${encodeURIComponent(result.slug)}`;
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  async function loadCategories(selected = "") {
    const nav = document.querySelector("#category-nav");
    if (!nav) return;
    try {
      const result = await api("/categories");
      const all = document.createElement("a");
      all.className = `category-link ${selected ? "" : "is-active"}`;
      all.href = `${fileUrl("/")}?sort=${encodeURIComponent(document.querySelector("#sort")?.value || "newest")}`;
      all.append(document.createTextNode("Все новости"));
      all.append(
        textElement(
          "span",
          result.data.reduce(
            (sum, item) => sum + Number(item.article_count),
            0,
          ),
        ),
      );
      nav.replaceChildren(all);
      for (const item of result.data) {
        const link = document.createElement("a");
        link.className = `category-link ${selected === item.slug ? "is-active" : ""}`;
        link.href = `${fileUrl("/")}?category=${encodeURIComponent(item.slug)}`;
        link.append(document.createTextNode(item.name));
        link.append(textElement("span", item.article_count));
        nav.append(link);
      }
      const authors = document.querySelector("#popular-authors");
      if (authors) {
        const authorLinks = result.popular_authors.map((author) => {
          const link = document.createElement("a");
          link.className = "popular-author";
          link.href = `${fileUrl("/profile")}?id=${author.id}`;
          const image = document.createElement("img");
          image.src =
            author.avatar_url || fileUrl("/assets/images/avatar-default.svg");
          image.alt = "";
          const name = document.createElement("span");
          name.textContent = author.name;
          if (["moderator", "admin"].includes(author.role)) {
            name.className = `role-${author.role}`;
          }
          const count = document.createElement("small");
          count.textContent = `${author.follower_count} подписчиков`;
          const tags = document.createElement("span");
          tags.className = "author-profile-tags";
          (author.profile_tags || "")
            .split(",")
            .filter(Boolean)
            .forEach((tag) => tags.append(textElement("span", tag, "tag-chip")));
          link.append(image, name, count, tags);
          return link;
        });
        authors.replaceChildren(...authorLinks);
      }
    } catch {

    }
  }

  async function loadFeed() {
    const list = document.querySelector("#article-list");
    if (!list) return;
    const params = new URLSearchParams(window.location.search);
    const category = params.get("category") || "";
    const search = params.get("q") || "";
    const sort =
      document.querySelector("#sort")?.value || params.get("sort") || "newest";
    const searchInput = document.querySelector("#site-search");
    if (searchInput) searchInput.value = search;
    const sortInput = document.querySelector("#sort");
    if (sortInput) sortInput.value = sort;
    await loadCategories(category);
    try {
      const query = new URLSearchParams({ sort, limit: "30" });
      if (category) query.set("category", category);
      if (search) query.set("q", search);
      const result = await api(`/articles?${query}`);
      cacheWrite(`feed-${query.toString()}`, result);
      list.replaceChildren(...result.data.map(articleCard));
      const active = document.querySelector(".category-link.is-active");
      document.querySelector("#feed-title").textContent = search
        ? `Результаты поиска`
        : active?.childNodes[0]?.textContent?.trim() || "Главное сегодня";
      if (!result.data.length)
        list.append(
          textElement(
            "p",
            "По этому запросу пока нет опубликованных материалов.",
            "empty-state",
          ),
        );
    } catch (error) {
      if (!isFilePreview) showNotice(error.message, "error");
    }
  }

  function renderComment(comment) {
    const box = document.createElement("div");
    box.className = "comment-box";
    const meta = document.createElement("div");
    meta.className = "comment-meta";
    if (comment.user_id) {
      const author = document.createElement("a");
      author.href = `${fileUrl("/profile")}?id=${comment.user_id}`;
      author.textContent = comment.name;
      if (["moderator", "admin"].includes(comment.role)) author.classList.add(`role-${comment.role}`);
      meta.append(author, document.createTextNode(" · "));
    } else meta.append(document.createTextNode(`${comment.name} · `));
    meta.append(
      document.createTextNode(
        new Date(`${comment.created_at.replace(" ", "T")}Z`).toLocaleString(
          "ru-RU",
        ),
      ),
    );
    box.append(meta);
    box.append(textElement("p", comment.body));
    return box;
  }

  async function loadArticle() {
    const title = document.querySelector("#story-title");
    if (!title) return;
    const params = new URLSearchParams(window.location.search);
    const parts = window.location.pathname.split("/").filter(Boolean);
    const slug = params.get("slug") || (parts[0] === "article" ? parts[1] : "");
    if (!slug || isFilePreview) return;
    try {
      const result = await api(`/article?slug=${encodeURIComponent(slug)}`);
      csrfToken = result.csrf;
      const item = result.article;
      window.currentArticleId = item.id;
      document.title = `${item.title} — Дайджест`;
      title.textContent = item.title;
      document.querySelector("#story-subtitle").textContent = item.subtitle;
      document.querySelector("#story-author").textContent = item.author_name;
      const publishedDate = new Date(`${item.published_at.replace(" ", "T")}Z`);
      document.querySelector("#story-date").textContent =
        publishedDate.toLocaleDateString("ru-RU");
      document
        .querySelector("#story-date")
        .setAttribute("datetime", publishedDate.toISOString());
      document.querySelector("#story-views").textContent =
        `${Number(item.views).toLocaleString("ru-RU")} просмотров`;
      const category = document.querySelector("#story-category");
      category.textContent = item.category_name;
      category.href = `${fileUrl("/")}?category=${encodeURIComponent(item.category_slug)}`;
      const body = document.querySelector("#story-body");
      body.innerHTML = item.body;
      const image = document.querySelector("#story-image");
      if (item.image_url) {
        image.src = item.image_url;
        image.hidden = false;
        image.addEventListener(
          "error",
          () => {
            image.hidden = true;
          },
          { once: true },
        );
      }
      const sourceSlot = document.querySelector("#story-source");
      if (item.source_url) {
        sourceSlot.hidden = false;
        sourceSlot.replaceChildren(document.createTextNode("Источник: "));
        const source = document.createElement("a");
        source.href = item.source_url;
        source.target = "_blank";
        source.rel = "noopener noreferrer";
        source.textContent = "первоисточник ↗";
        sourceSlot.append(source);
      }
      const tags = document.querySelector("#story-tags");
      tags.replaceChildren(
        ...result.tags.map((tag) => {
          const link = document.createElement("a");
          link.className = "tag-chip";
          link.href = isFilePreview
            ? `${fileUrl("/tag/index.html")}?slug=${encodeURIComponent(tag.slug)}`
            : `/tag/${encodeURIComponent(tag.slug)}`;
          link.textContent = `#${tag.name}`;
          return link;
        }),
      );
      const related = document.querySelector("#related-list");
      related.replaceChildren(...result.related.map(articleCard));
      if (!result.related.length)
        related.append(
          textElement("p", "Похожих статей пока нет.", "empty-state"),
        );
      const comments = document.querySelector("#comments-list");
      comments.replaceChildren(...result.comments.map(renderComment));
      document.querySelector("#comment-count").textContent =
        result.comments.length;
      document.querySelector("#rating-summary").textContent =
        `Оценка: ${result.rating.average || "—"} / 5 (${result.rating.total})`;
      currentRating = Number(result.my_rating || 0);
      paintRatingStars(currentRating, currentRating);
      for (const button of document.querySelectorAll(".follow-button")) {
        const type = button.dataset.followType;
        button.textContent = result.followed[type]
          ? "Подписка активна"
          : type === "category"
            ? "Подписаться на рубрику"
            : "Подписаться на автора";
        button.dataset.targetId =
          type === "category" ? item.category_id : item.author_user_id;
      }
      currentUser = result.user;
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  function paintRatingStars(value, selectedValue = currentRating) {
    document.querySelectorAll(".rating-star").forEach((star) => {
      const rating = Number(star.dataset.value);
      star.classList.toggle("is-active", rating <= value);
      star.setAttribute("aria-checked", String(rating === selectedValue));
    });
  }

  async function loadTag() {
    if (isFilePreview) return;
    const params = new URLSearchParams(window.location.search);
    const parts = window.location.pathname.split("/").filter(Boolean);
    const slug = params.get("slug") || (parts[0] === "tag" ? parts[1] : "");
    const list = document.querySelector("#tag-list");
    if (!slug || !list) return;
    try {
      const result = await api(
        `/articles?tag=${encodeURIComponent(slug)}&limit=50`,
      );
      csrfToken = result.csrf || csrfToken;
      list.replaceChildren(...result.data.map(articleCard));
      document.querySelector("#tag-title").textContent =
        `#${slug.replaceAll("-", " ")}`;
      if (!result.data.length)
        list.append(
          textElement(
            "p",
            "Материалы с таким тегом не найдены.",
            "empty-state",
          ),
        );
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  function profileFollowAvatar(item) {
    const link = document.createElement("a");
    link.className = "profile-follow-avatar";
    link.href = `${fileUrl("/profile")}?id=${item.author_id}`;
    link.title = `${item.author_name} · ${item.follower_count || 0} подписчиков`;
    const image = document.createElement("img");
    image.src = item.author_avatar || fileUrl("/assets/images/avatar-default.svg");
    image.alt = item.author_name || "";
    link.append(image);
    return link;
  }

  function openFollowModal(authors) {
    const modal = createModal("Все подписки на авторов");
    const list = document.createElement("div");
    list.className = "switch-account-list";
    authors.forEach((item) => {
      const row = document.createElement("div");
      row.className = "follow-author-row";
      const link = document.createElement("a");
      link.href = `${fileUrl("/profile")}?id=${item.author_id}`;
      link.className = "follow-author-main";
      const image = document.createElement("img");
      image.src = item.author_avatar || fileUrl("/assets/images/avatar-default.svg");
      image.alt = "";
      const copy = document.createElement("span");
      copy.append(textElement("strong", item.author_name), textElement("small", `${item.follower_count || 0} подписчиков`));
      const tags = document.createElement("span");
      tags.className = "follow-author-tags";
      (item.profile_tags || "").split(",").filter(Boolean).forEach((tag) => tags.append(textElement("span", tag, "tag-chip")));
      copy.append(tags); link.append(image, copy);
      const button = textElement("button", item.is_followed ? "Подписан" : "Подписаться", "small-button");
      button.type = "button";
      button.disabled = Boolean(item.is_followed);
      button.addEventListener("click", async () => {
        try {
          await api("/actions", { method: "POST", body: JSON.stringify({ action: "follow", target_type: "author", target_id: item.author_id, csrf: csrfToken }) });
          button.textContent = "Подписан";
          button.disabled = true;
        } catch (error) {
          showNotice(error.message, "error");
        }
      });
      row.append(link, button); list.append(row);
    });
    modal.panel.append(list);
  }

  function openProfileBanModal(profile) {
    const modal = createModal(`Блокировка: ${profile.name}`);
    const form = document.createElement("form");
    form.innerHTML = `<label class="form-field">Дней (0 — навсегда)<input class="form-control" name="days" type="number" min="0" value="7" required></label><label class="form-field">Причина<textarea class="form-control" name="reason" maxlength="1000"></textarea></label><button class="primary-button" type="submit">Заблокировать</button>`;
    form.addEventListener("submit", async (event) => { event.preventDefault(); try { await api("/account", { method: "POST", body: JSON.stringify({ action: "ban-user", user_id: profile.id, ...Object.fromEntries(new FormData(form).entries()), csrf: csrfToken }) }); modal.backdrop.remove(); showNotice("Аккаунт заблокирован."); loadProfile(); } catch (error) { showNotice(error.message, "error"); } });
    modal.panel.append(form);
  }

  async function loadProfile() {
    const params = new URLSearchParams(window.location.search);
    const parts = window.location.pathname.split("/").filter(Boolean);
    const id = params.get("id") || (parts[0] === "profile" ? parts[1] : "");
    if (!id || isFilePreview) return;
    try {
      const result = await api(`/account?id=${encodeURIComponent(id)}&view=profile&sort=${encodeURIComponent(document.querySelector("#profile-sort")?.value || "newest")}`);
      const profile = result.profile;
      document.title = `${profile.name} — Дайджест`;
      const profileName = document.querySelector("#profile-name");
      profileName.textContent = profile.name;
      profileName.className = `role-${profile.role}`;
      document.querySelector("#profile-bio").textContent =
        profile.bio || "Описание профиля не добавлено.";
      document.querySelector("#profile-role").textContent =
        {
          viewer: "Зритель",
          author: "Автор",
          moderator: "Модератор",
          admin: "Администратор",
        }[profile.role] || profile.role;
      document.querySelector("#profile-role").className = `metric role-${profile.role}`;
      if (profile.avatar_url)
        document.querySelector("#profile-avatar").src = profile.avatar_url;
      const tagTarget = document.querySelector("#profile-tags");
      tagTarget?.replaceChildren(...(result.tags || []).map((tag) => textElement("span", tag.name, "tag-chip")));
      const rep = document.querySelector("#profile-reputation");
      rep?.replaceChildren(textElement("strong", `Репутация: ${profile.reputation || 0}`));
      if (rep && result.can_vote) {
        for (const [value, label] of [[1, "▲"], [-1, "▼"]]) {
          const button = textElement("button", label, "reputation-button");
          button.type = "button";
          button.title = value > 0 ? "Повысить репутацию" : "Понизить репутацию";
          button.addEventListener("click", async () => {
            try {
              const saved = await api("/account", { method: "POST", body: JSON.stringify({ action: "reputation", profile_id: profile.id, value, csrf: csrfToken }) });
              profile.reputation = saved.reputation;
              rep.querySelector("strong").textContent = `Репутация: ${saved.reputation}`;
            } catch (error) { showNotice(error.message, "error"); }
          });
          rep.append(button);
        }
      }
      const profileActions = document.querySelector("#profile-actions");
      profileActions?.replaceChildren();
      if (result.is_owner) {
        const edit = document.createElement("a"); edit.className = "outline-button"; edit.href = fileUrl("/settings"); edit.textContent = "Редактировать профиль"; profileActions?.append(edit);
      } else {
        if (result.can_moderate) {
        const ban = textElement("button", "Заблокировать", "small-button reject"); ban.type = "button"; ban.addEventListener("click", () => openProfileBanModal(profile)); profileActions?.append(ban);
        }
        if (result.can_follow) {
          const follow = textElement("button", result.is_follower ? "Отписаться" : "Подписаться", "outline-button follow-profile-button");
          follow.type = "button";
          follow.addEventListener("click", async () => {
            try {
              const saved = await api("/actions", { method: "POST", body: JSON.stringify({ action: "follow", target_type: "author", target_id: profile.id, csrf: csrfToken }) });
              result.is_follower = saved.followed;
              follow.textContent = saved.followed ? "Отписаться" : "Подписаться";
            } catch (error) { showNotice(error.message, "error"); }
          });
          profileActions?.append(follow);
        }
      }
      const renderProfileArticles = () =>
        renderList(
          "#profile-articles",
          result.articles.map((item) => ({
            ...item,
            author: profile.name,
            author_id: profile.id,
            author_role: profile.role,
            author_tags: (result.tags || []).map((tag) => tag.name).join(","),
          })),
          articleCard,
        );
      renderProfileArticles();
      const sort = document.querySelector("#profile-sort");
      if (sort) sort.onchange = loadProfile;
      const collapse = document.querySelector("#profile-articles-toggle");
      if (collapse)
        collapse.onclick = () => {
          const target = document.querySelector("#profile-articles");
          target.hidden = !target.hidden;
          collapse.textContent = target.hidden ? "Развернуть" : "Свернуть";
        };
      const comments = document.querySelector("#profile-comments");
      comments?.replaceChildren(
        ...(result.comments || []).map((item) => {
          const card = document.createElement("div");
          card.className = "comment-box";
          const link = document.createElement("a");
          link.href = `${fileUrl("/article")}?slug=${encodeURIComponent(item.slug)}`;
          link.textContent = item.article_title;
          card.append(
            textElement(
              "div",
              new Date(`${item.created_at.replace(" ", "T")}Z`).toLocaleString(
                "ru-RU",
              ),
              "comment-meta",
            ),
            link,
            textElement("p", item.body),
          );
          return card;
        }),
      );
      if (!(result.comments || []).length)
        comments?.append(
          textElement(
            "p",
            result.can_see_comments
              ? "Комментариев пока нет."
              : "Комментарии скрыты настройками приватности.",
            "metric",
          ),
        );
      const followRow = document.querySelector("#profile-follow-row");
      const authors = (result.follows || []).filter((item) => item.target_type === "author");
      followRow?.replaceChildren();
      if (!result.can_see_follows) followRow?.append(textElement("span", "Подписки скрыты настройками приватности.", "metric"));
      else if (!authors.length) followRow?.append(textElement("span", "Подписок на авторов пока нет.", "metric"));
      else {
        authors.slice(0, 9).forEach((item) => followRow?.append(profileFollowAvatar(item)));
        if (authors.length > 9) { const more = textElement("button", "→", "profile-follow-more"); more.type = "button"; more.title = "Показать все подписки"; more.addEventListener("click", () => openFollowModal(authors)); followRow?.append(more); }
      }
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  async function loadSubscriptions() {
    if (isFilePreview) return;
    const cached = cacheRead("subscriptions");
    if (cached) renderSubscriptionData(cached);
    try {
      const sort = document.querySelector("#sort")?.value || "newest";
      const result = await api(`/account?view=follows&sort=${encodeURIComponent(sort)}`);
      csrfToken = result.csrf || csrfToken;
      cacheWrite("subscriptions", { follows: result.follows, articles: result.articles });
      renderSubscriptionData(result);
      await loadCategories();
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  function renderSubscriptionData(result) {
    renderAccountActions(currentUser || cacheRead("user"));
    const authors = document.querySelector("#subscription-authors");
    const categories = document.querySelector("#subscription-categories");
    const authorFollows = result.follows.filter((item) => item.target_type === "author");
    const categoryFollows = result.follows.filter((item) => item.target_type === "category");

    if (categories) {
      categories.replaceChildren(
        ...categoryFollows.map((item) => {
          const link = document.createElement("a");
          link.className = "tag-chip";
          link.href = `${fileUrl("/")}?category=${item.category_slug}`;
          link.textContent = item.category_name;
          return link;
        }),
      );
    }

    authors.replaceChildren(
      ...authorFollows.map((item) => {
        const card = document.createElement("article");
        card.className = "follow-author-row";
        const link = document.createElement("a");
        link.className = "follow-author-main";
        link.href = `${fileUrl("/profile")}?id=${item.author_id}`;
        const image = document.createElement("img");
        image.src = item.author_avatar || fileUrl("/assets/images/avatar-default.svg");
        image.alt = "";
        const info = document.createElement("span");
        info.append(
          textElement("strong", item.author_name, `role-${item.author_role}`),
          textElement("small", `${item.follower_count || 0} подписчиков`),
        );
        const tags = document.createElement("span");
        tags.className = "follow-author-tags";
        (item.profile_tags || "")
          .split(",")
          .filter(Boolean)
          .forEach((tag) => tags.append(textElement("span", tag, "tag-chip")));
        info.append(tags);
        link.append(image, info);

        const button = textElement("button", "Подписан", "small-button");
        button.type = "button";
        button.addEventListener("click", async () => {
          try {
            await api("/actions", {
              method: "POST",
              body: JSON.stringify({
                action: "follow",
                target_type: "author",
                target_id: item.author_id,
                csrf: csrfToken,
              }),
            });
            loadSubscriptions();
          } catch (error) {
            showNotice(error.message, "error");
          }
        });
        card.append(link, button);
        return card;
      }),
    );
    renderList("#article-list", result.articles || [], articleCard);

    if (categories && !categories.children.length) {
      categories.append(textElement("span", "Рубрик пока нет.", "metric"));
    }
    if (!authors.children.length) {
      authors.append(textElement("span", "Авторов пока нет.", "metric"));
    }
  }

  async function initSettings() {
    if (isFilePreview) return;
    const cached = cacheRead("settings");
    if (cached?.user) applySettingsCache(cached);
    try {
      const result = await api("/account");
      cacheWrite("settings", { user: result.user, tags: result.tags, available_tags: result.available_tags });
      csrfToken = result.csrf;
      currentUser = result.user;
      cacheWrite("user", currentUser);
      renderAccountActions(currentUser);
      const profileForm = document.querySelector("#profile-form");
      for (const field of ["name", "bio"])
        profileForm.elements[field].value = currentUser[field] || "";
      const tagsSelect = document.querySelector("#profile-tags-select");
      if (tagsSelect) tagsSelect.replaceChildren(...(result.available_tags || []).map((tag) => { const option = document.createElement("option"); option.value = tag.id; option.textContent = tag.name; option.selected = (result.tags || []).some((saved) => Number(saved.id) === Number(tag.id)); return option; }));
      if (tagsSelect) {
        tagsSelect.addEventListener("change", () => {
          const selected = [...tagsSelect.selectedOptions];
          if (selected.length > 3) selected.slice(3).forEach((option) => { option.selected = false; });
          [...tagsSelect.options].forEach((option) => {
            option.disabled = tagsSelect.selectedOptions.length >= 3 && !option.selected;
          });
        });
        tagsSelect.dispatchEvent(new Event("change"));
      }
      document.querySelector(
        "#privacy-form",
      ).elements.comments_visibility.value =
        currentUser.comments_visibility || "everyone";
      document.querySelector(
        "#privacy-form",
      ).elements.follows_visibility.value =
        currentUser.follows_visibility || "everyone";
      document.querySelector("#two-factor").checked = Boolean(
        Number(currentUser.two_factor_enabled),
      );
      const sessions = document.querySelector("#session-list");
      sessions.replaceChildren(
        ...result.sessions.map((item) => {
          const row = document.createElement("div");
          row.className = "session-row";
          row.append(
            textElement(
              "strong",
              `${item.device_label || "Браузер"}${item.is_current ? " · это устройство" : ""}`,
            ),
            textElement(
              "span",
              `${item.ip_address || "локальный адрес"} · ${new Date(`${item.last_seen.replace(" ", "T")}Z`).toLocaleString("ru-RU")}`,
              "metric",
            ),
          );
          return row;
        }),
      );
      profileForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        try {
          const formData = new FormData(profileForm);
          formData.append("action", "update-profile");
          formData.append("csrf", csrfToken);
          formData.delete("profile_tags");
          formData.append("profile_tags", JSON.stringify([...tagsSelect.selectedOptions].slice(0, 3).map((option) => Number(option.value))));
          const response = await fetch(`${apiRoot}/account`, { method: "POST", body: formData, credentials: "same-origin" });
          const data = await response.json();
          if (!response.ok) throw new Error(data.error || "Не удалось сохранить профиль.");
          const updated = { ...currentUser, name: profileForm.elements.name.value, bio: profileForm.elements.bio.value, avatar_url: data.avatar_url || currentUser.avatar_url };
          currentUser = updated; cacheWrite("user", updated); renderAccountActions(updated);
          const fresh = await api("/account"); cacheWrite("settings", { user: fresh.user, tags: fresh.tags, available_tags: fresh.available_tags });
          showNotice("Профиль обновлён.");
        } catch (error) { showNotice(error.message, "error"); }
      });
      document
        .querySelector("#password-form")
        .addEventListener("submit", async (event) => {
          event.preventDefault();
          await submitAccountForm(
            event.currentTarget,
            "change-password",
            "Пароль изменён.",
          );
        });
      document
        .querySelector("#privacy-form")
        .addEventListener("submit", async (event) => {
          event.preventDefault();
          await submitAccountForm(
            event.currentTarget,
            "privacy",
            "Настройки приватности сохранены.",
          );
        });
      document
        .querySelector("#two-factor")
        .addEventListener("change", async (event) => {
          await submitAccountPayload(
            { action: "two-factor", enabled: event.target.checked },
            "Опция двухфакторной аутентификации обновлена.",
          );
        });
      document
        .querySelector("#logout-other")
        .addEventListener("click", async () => {
          await submitAccountPayload(
            { action: "logout-other-sessions" },
            "Другие устройства отключены.",
          );
        });
      document
        .querySelector("#delete-account-button")
        .addEventListener("click", openDeleteAccountModal);
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  function applySettingsCache(result) {
    const profileForm = document.querySelector("#profile-form");
    if (!profileForm || !result?.user) return;
    for (const field of ["name", "bio"]) profileForm.elements[field].value = result.user[field] || "";
    const privacy = document.querySelector("#privacy-form");
    if (privacy) {
      privacy.elements.comments_visibility.value = result.user.comments_visibility || "everyone";
      privacy.elements.follows_visibility.value = result.user.follows_visibility || "everyone";
    }
    const twoFactor = document.querySelector("#two-factor");
    if (twoFactor) twoFactor.checked = Boolean(Number(result.user.two_factor_enabled));
    const tagSelect = document.querySelector("#profile-tags-select");
    if (tagSelect && result.available_tags) tagSelect.replaceChildren(...result.available_tags.map((tag) => { const option = document.createElement("option"); option.value = tag.id; option.textContent = tag.name; option.selected = (result.tags || []).some((saved) => Number(saved.id) === Number(tag.id)); return option; }));
  }

  async function submitAccountForm(form, action, message) {
    await submitAccountPayload(
      { ...Object.fromEntries(new FormData(form).entries()), action },
      message,
    );
  }
  async function submitAccountPayload(payload, message) {
    const result = await api("/account", {
      method: "POST",
      body: JSON.stringify({ ...payload, csrf: csrfToken }),
    });
    if (result.ok) showNotice(message);
    return result;
  }

  function openDeleteAccountModal() {
    const modal = createModal("Удалить аккаунт");
    const form = document.createElement("form");
    form.innerHTML = `<p class="form-note">Введите DELETE и текущий пароль. Все материалы аккаунта будут удалены.</p><label class="form-field">Подтверждение<input class="form-control" name="confirmation" autocomplete="off" required /></label><label class="form-field">Пароль<input class="form-control" name="password" type="password" required /></label><button class="outline-button danger-button" type="submit">Удалить навсегда</button>`;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        const result = await submitAccountPayload(
          {
            action: "delete-account",
            ...Object.fromEntries(new FormData(form).entries()),
          },
          "Аккаунт удалён.",
        );
        clearAccountCache();
        if (result.user) cacheWrite("user", result.user);
        window.location.replace(fileUrl(result.redirect || "/"));
      } catch (error) {
        showNotice(error.message, "error");
      }
    });
    modal.panel.append(form);
  }

  async function loadDashboard() {
    if (isFilePreview) return;
    const cached = cacheRead("dashboard");
    if (cached?.user) {
      currentUser = cached.user;
      document.querySelector("#dashboard-greeting").textContent = `Добрый день, ${cached.user.name}`;
      renderAccountActions(cached.user);
      renderStats(cached.stats, cached.user.role);
      renderList("#my-history", cached.history || [], articleCard);
      dashboardArticles = cached.articles || cached.manage_articles || [];
      renderPublications(dashboardArticles, cached.user.role);
      dashboardUsers = cached.users || [];
      if (["moderator", "admin"].includes(cached.user.role) && cached.users) renderUsers(dashboardUsers);
      if (cached.pending) {
        document.querySelector("#moderation-section").hidden = false;
        renderList(
          "#pending-list",
          cached.pending,
          (item) => articleCard({
            ...item,
            category: item.category_name,
            author: item.author_name,
            published_at: item.published_at || item.created_at,
          }),
        );
      }
      if (cached.banned_content) renderBannedContent(cached.banned_content);
    }
    try {
      const data = await api("/dashboard");
      cacheWrite("dashboard", {
        user: data.user,
        stats: data.stats,
        history: data.history,
        articles: data.articles,
        manage_articles: data.manage_articles,
        pending: data.pending,
        comments_pending: data.comments_pending,
        banned_content: data.banned_content,
        popular: data.popular,
        users: data.users,
      });
      csrfToken = data.csrf;
      currentUser = data.user;
      document.querySelector("#dashboard-greeting").textContent =
        `Добрый день, ${data.user.name}`;
      const roleNames = {
        viewer: "Зритель",
        author: "Автор",
        moderator: "Модератор",
        admin: "Администратор",
      };
      document.querySelector("#dashboard-role").textContent =
        roleNames[data.user.role] || data.user.role;
      renderAccountActions(data.user);
      const canEdit = ["author", "admin"].includes(data.user.role);
      document.querySelector("#new-story-link").hidden = !canEdit;
      renderStats(data.stats, data.user.role);
      renderList("#my-history", data.history, (item) =>
        articleCard({
          ...item,
          author: item.author_name || data.user.name,
          author_id: item.author_id || data.user.id,
          author_role: item.author_role || data.user.role,
          category: new Date(item.viewed_at).toLocaleDateString("ru-RU"),
        }),
      );
      dashboardArticles = data.articles || data.manage_articles || [];
      publicationsExpanded = false;
      renderPublications(dashboardArticles, data.user.role);
      const publicationsSearch = document.querySelector("#publications-search");
      if (publicationsSearch) publicationsSearch.oninput = () => renderPublications(dashboardArticles, currentUser.role);
      const ownFilter = document.querySelector("#own-publications-filter");
      const ownOnly = document.querySelector("#publications-own-only");
      const publicationsToggle = document.querySelector("#publications-toggle");
      const canFilterOwn = ["moderator", "admin"].includes(data.user.role);
      if (ownFilter) ownFilter.hidden = !canFilterOwn;
      if (ownOnly) ownOnly.onchange = () => renderPublications(dashboardArticles, currentUser.role);
      if (publicationsToggle) publicationsToggle.onclick = () => {
        publicationsExpanded = !publicationsExpanded;
        renderPublications(dashboardArticles, currentUser.role);
      };
      if (data.pending) {
        document.querySelector("#moderation-section").hidden = false;
        const pending = document.querySelector("#pending-list");
        pending.replaceChildren(
          ...data.pending.map((item) => {
            const card = articleCard({
              ...item,
              category: item.category_name,
              author: item.author_name,
            });
            const row = card.querySelector(".article-copy");
            const note = document.createElement("input");
            note.className = "form-control";
            note.placeholder = "Причина возврата";
            const controls = document.createElement("div");
            controls.className = "table-actions";
            controls.append(
              actionButton(
                "Опубликовать",
                "moderate",
                { article_id: item.id, decision: "approved" },
                "approve",
              ),
            );
            controls.append(note);
            controls.append(
              actionButton(
                "Вернуть автору",
                "moderate",
                { article_id: item.id, decision: "rejected", note: "" },
                "reject",
              ),
            );
            controls.lastChild.addEventListener("submit", (event) => {
              event.preventDefault();
              api("/actions", {
                method: "POST",
                body: JSON.stringify({
                  action: "moderate",
                  article_id: item.id,
                  decision: "rejected",
                  note: note.value,
                  csrf: csrfToken,
                }),
              })
                .then(loadDashboard)
                .catch((error) => showNotice(error.message, "error"));
            });
            controls.append(
              actionButton(
                "Удалить",
                "delete-article",
                { article_id: item.id },
                "reject",
              ),
            );
            row.append(controls);
            return card;
          }),
        );
        const commentCards = data.comments_pending || [];
        for (const comment of commentCards) {
          const card = document.createElement("div");
          card.className = "comment-box";
          card.append(
            textElement(
              "div",
              `${comment.user_name} · ${comment.article_title}`,
              "comment-meta",
            ),
            textElement("p", comment.body),
            actionButton(
              "Скрыть комментарий",
              "hide-comment",
              { comment_id: comment.id },
              "reject",
            ),
          );
          pending.append(card);
        }
      }
      dashboardUsers = data.users || [];
      const usersSearch = document.querySelector("#users-search");
      if (usersSearch) usersSearch.oninput = () => renderUsers(dashboardUsers);
      const usersToggle = document.querySelector("#users-toggle");
      if (usersToggle) usersToggle.onclick = () => {
        usersExpanded = !usersExpanded;
        renderUsers(dashboardUsers);
      };
      if (["moderator", "admin"].includes(data.user.role)) renderUsers(dashboardUsers);
      else document.querySelector("#users-section").hidden = true;
      if (data.server) {
        renderServer(data.server);
        if (serverTimer) window.clearInterval(serverTimer);
        serverTimer = window.setInterval(async () => {
          try {
            const fresh = await api("/metrics");
            if (fresh.server) renderServer(fresh.server);
          } catch {

          }
        }, 5000);
      }
      if (data.popular) {
        document.querySelector("#popular-section").hidden = false;
        renderList("#popular-list", data.popular, (item) =>
          articleCard({ ...item, category: `${item.views} просмотров` }),
        );
      }
      if (data.banned_content) renderBannedContent(data.banned_content);
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  function renderStats(stats, role) {
    const labels = {
      users: "Пользователи",
      published: "Опубликовано",
      pending: "На проверке",
      comments: "Комментарии",
      draft: "Черновики",
      review: "На проверке",
      rejected: "Возвращено",
    };
    const items = Array.isArray(stats)
      ? stats.map((item) => [labels[item.status] || item.status, item.total])
      : Object.entries(stats || {}).map(([key, value]) => [
          labels[key] || key,
          value,
        ]);
    const grid = document.querySelector("#dashboard-stats");
    grid.replaceChildren(
      ...items.map(([label, value]) => {
        const panel = document.createElement("div");
        panel.className = "stat-panel";
        panel.append(
          textElement("span", label),
          textElement("strong", Number(value).toLocaleString("ru-RU")),
        );
        return panel;
      }),
    );
    if (!items.length)
      grid.append(
        textElement(
          "p",
          role === "viewer"
            ? "Пока нет собственных публикаций."
            : "Нет данных для отображения.",
          "empty-state",
        ),
      );
  }

  function renderList(selector, items, render) {
    const target = document.querySelector(selector);
    if (!target) return;
    target.replaceChildren(...items.map(render));
    if (!items.length)
      target.append(textElement("p", "Пока нет материалов.", "empty-state"));
  }

  function renderFollows(items) {
    const target = document.querySelector("#my-follows");
    target.replaceChildren(
      ...items.map((item) =>
        textElement(
          "span",
          item.target_type === "category"
            ? `Рубрика: ${item.category_name}`
            : `Автор: ${item.author_name}`,
          "tag-chip",
        ),
      ),
    );
    if (!items.length)
      target.append(textElement("span", "Подписок пока нет.", "metric"));
  }

  function renderPublications(items, role) {
    const section = document.querySelector("#publications-section");
    const target = document.querySelector("#publications-list");
    const toggle = document.querySelector("#publications-toggle");
    if (!section || !target) return;
    section.hidden = false;

    const search = document.querySelector("#publications-search")?.value.trim().toLocaleLowerCase("ru");
    const ownOnly = document.querySelector("#publications-own-only")?.checked;
    const visible = items.filter((item) => {
      if (ownOnly && Number(item.author_id) !== Number(currentUser?.id)) return false;
      if (!search) return true;
      return [item.title, item.subtitle, item.author_name, item.category_name]
        .some((value) => String(value || "").toLocaleLowerCase("ru").includes(search));
    });

    target.classList.toggle("collection-collapsed", !publicationsExpanded && visible.length > 6);
    if (toggle) {
      toggle.hidden = visible.length <= 6;
      toggle.setAttribute("aria-expanded", String(publicationsExpanded));
      toggle.textContent = publicationsExpanded ? "⌃" : "⌄";
      toggle.setAttribute("aria-label", publicationsExpanded ? "Свернуть публикации" : "Показать все публикации");
      toggle.title = publicationsExpanded ? "Свернуть публикации" : "Показать все публикации";
    }
    target.replaceChildren(
      ...visible.map((item) => {
        const card = articleCard({
          ...item,
          category: item.category_name || item.status,
          author: item.author_name || currentUser?.name,
          author_id: item.author_id || item.author_user_id,
          author_role: item.author_role || (Number(item.author_id) === Number(currentUser?.id) ? currentUser?.role : ""),
          published_at: item.published_at || item.created_at,
        });
        const controls = document.createElement("div");
        controls.className = "table-actions";
        const isOwn = Number(item.author_id) === Number(currentUser?.id);
        if (role === "admin" || (role === "author" && isOwn)) {
          const edit = document.createElement("a");
          edit.className = "small-button";
          edit.href = `${fileUrl("/editor/index.html")}?id=${item.id}`;
          edit.textContent = "Редактировать";
          controls.append(edit);
        }
        if (role === "admin" || role === "moderator" || (role === "author" && isOwn)) {
          controls.append(actionButton("Удалить", "delete-article", { article_id: item.id }, "reject"));
        }
        card.querySelector(".article-copy").append(
          textElement("div", `Статус: ${item.status}`, "metric"),
          controls,
        );
        return card;
      }),
    );
    if (!visible.length) target.append(textElement("p", "Публикаций не найдено.", "empty-state"));
  }

  function renderServer(server) {
    const target = document.querySelector("#server-stats");
    document.querySelector("#server-section").hidden = false;
    const rows = [
      ["Операционная система", server.system],
      ["Версия PHP", server.php],
      ["Веб-сервер", server.web_server],
      [
        "Свободное место",
        server.disk_free_gb == null
          ? "Недоступно"
          : `${server.disk_free_gb} ГБ`,
      ],
      [
        "Размер диска",
        server.disk_total_gb == null
          ? "Недоступно"
          : `${server.disk_total_gb} ГБ`,
      ],
      ["Память PHP", `${server.php_memory_mb} МБ из ${server.memory_limit}`],
      [
        "Загрузка CPU",
        server.cpu_percent == null ? "Недоступно" : `${server.cpu_percent}%`,
      ],
      [
        "Использование RAM",
        server.ram_percent == null
          ? "Недоступно"
          : `${server.ram_percent}% (${server.ram_used_gb} из ${server.ram_total_gb} ГБ)`,
      ],
    ];
    target.replaceChildren(
      ...rows.map(([label, value]) => {
        const panel = document.createElement("div");
        panel.className = "stat-panel";
        panel.append(textElement("span", label), textElement("strong", value));
        return panel;
      }),
    );
  }

  function renderUsers(users) {
    const target = document.querySelector("#users-list");
    const section = document.querySelector("#users-section");
    if (!target || !section) return;
    section.hidden = false;
    const search = document.querySelector("#users-search")?.value.trim().toLocaleLowerCase("ru");
    const visible = users.filter((account) => !search || [account.name, account.email, account.role].some((value) => String(value || "").toLocaleLowerCase("ru").includes(search)));
    target.classList.toggle("users-collapsed", !usersExpanded && visible.length > 9);
    const toggle = document.querySelector("#users-toggle");
    if (toggle) {
      toggle.hidden = visible.length <= 9;
      toggle.setAttribute("aria-expanded", String(usersExpanded));
      toggle.textContent = usersExpanded ? "⌃" : "⌄";
      toggle.setAttribute("aria-label", usersExpanded ? "Свернуть список пользователей" : "Показать всех пользователей");
      toggle.title = usersExpanded ? "Свернуть список пользователей" : "Показать всех пользователей";
    }
    target.replaceChildren(
      ...visible.map((account) => {
        const card = document.createElement("div");
        card.className = "comment-box";
        card.append(
          (() => {
            const link = document.createElement("a");
            link.href = `${fileUrl("/profile")}?id=${account.id}`;
            link.textContent = account.name;
            link.className = "profile-link";
            return link;
          })(),
          textElement(
            "div",
            `${account.email} · ${account.role}`,
            "comment-meta",
          ),
        );
        if (Number(account.id) !== Number(currentUser.id)) {
          if (currentUser.role === "admin") {
            const form = document.createElement("form");
            form.className = "role-form";
            const select = document.createElement("select");
            select.className = "form-control";
            select.name = "role";
            for (const [value, label] of Object.entries({
              viewer: "Зритель",
              author: "Автор",
              moderator: "Модератор",
            })) {
              const option = document.createElement("option");
              option.value = value;
              option.textContent = label;
              option.selected = account.role === value;
              select.append(option);
            }
            form.append(
              select,
              textElement("button", "Сохранить", "small-button"),
            );
            form.lastChild.type = "submit";
            form.addEventListener("submit", async (event) => {
              event.preventDefault();
              try {
                await api("/actions", {
                  method: "POST",
                  body: JSON.stringify({
                    action: "role",
                    user_id: account.id,
                    role: select.value,
                    csrf: csrfToken,
                  }),
                });
                showNotice("Роль пользователя обновлена.");
                loadDashboard();
              } catch (error) {
                showNotice(error.message, "error");
              }
            });
            card.append(form);
          }
          if (dataUserCanModerate()) {
            const ban = textElement(
              "button",
              account.banned_until ? "Продлить блокировку" : "Заблокировать",
              "small-button reject",
            );
            ban.type = "button";
            ban.addEventListener("click", () => openBanModal(account));
            card.append(ban);
            if (account.banned_until)
              card.append(
                actionButton(
                  "Разблокировать",
                  "unban-user",
                  { user_id: account.id },
                  "approve",
                ),
              );
          }
        }
        return card;
      }),
    );
  }

  function dataUserCanModerate() {
    return ["moderator", "admin"].includes(currentUser?.role);
  }

  function openBanModal(account) {
    const modal = createModal(`Блокировка: ${account.name}`);
    const form = document.createElement("form");
    form.innerHTML = `<label class="form-field">Дней (0 — навсегда)<input class="form-control" name="days" type="number" min="0" value="7" required></label><label class="form-field">Причина<textarea class="form-control" name="reason" maxlength="1000"></textarea></label><button class="primary-button" type="submit">Заблокировать</button>`;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      try {
        await submitAccountPayload(
          {
            action: "ban-user",
            user_id: account.id,
            ...Object.fromEntries(new FormData(form).entries()),
          },
          "Аккаунт заблокирован.",
        );
        modal.backdrop.remove();
        loadDashboard();
      } catch (error) {
        showNotice(error.message, "error");
      }
    });
    modal.panel.append(form);
  }

  function renderBannedContent(data) {
    const section = document.querySelector("#banned-content-section");
    const target = document.querySelector("#banned-content-list");
    if (!section || !target) return;
    const cards = [];
    for (const article of data.articles || []) {
      const card = document.createElement("div");
      card.className = "comment-box";
      card.append(
        textElement("strong", `Статья: ${article.title}`),
        textElement(
          "p",
          `${article.author_name} · ${article.category_name}`,
          "comment-meta",
        ),
      );
      const actions = document.createElement("div");
      actions.className = "table-actions";
      for (const decision of ["delete", "hide", "restore"])
        actions.append(
          actionButton(
            decision === "delete"
              ? "Удалить"
              : decision === "hide"
                ? "Скрыть"
                : "Сохранить публичной",
            "resolve-banned-content",
            { content_type: "article", content_id: article.id, decision },
            decision === "delete" ? "reject" : "approve",
          ),
        );
      card.append(actions);
      cards.push(card);
    }
    for (const comment of data.comments || []) {
      const card = document.createElement("div");
      card.className = "comment-box";
      card.append(
        textElement("strong", `Комментарий: ${comment.article_title}`),
        textElement("p", `${comment.author_name}: ${comment.body}`),
      );
      const actions = document.createElement("div");
      actions.className = "table-actions";
      for (const decision of ["delete", "hide", "restore"])
        actions.append(
          actionButton(
            decision === "delete"
              ? "Удалить"
              : decision === "hide"
                ? "Скрыть"
                : "Сохранить публичным",
            "resolve-banned-content",
            { content_type: "comment", content_id: comment.id, decision },
            decision === "delete" ? "reject" : "approve",
          ),
        );
      card.append(actions);
      cards.push(card);
    }
    section.hidden = !cards.length;
    target.replaceChildren(...cards);
  }

  async function submitAuth(form, action) {
    const values = Object.fromEntries(new FormData(form).entries());
    try {
      const result = await api("/auth", {
        method: "POST",
        body: JSON.stringify({ ...values, action, csrf: csrfToken }),
      });
      csrfToken = result.csrf;
      clearAccountCache();
      cacheWrite("user", result.user);
      window.location.replace(fileUrl("/dashboard"));
    } catch (error) {
      showNotice(error.message, "error");
    }
  }

  async function initAuth() {
    const addAccountMode = new URLSearchParams(window.location.search).get("add-account") === "1";
    const state = await loadIdentity();
    if (state?.user && !addAccountMode) {
      window.location.replace(fileUrl("/dashboard"));
      return;
    }
    document
      .querySelector("#login-form")
      ?.addEventListener("submit", (event) => {
        event.preventDefault();
        submitAuth(event.currentTarget, "login");
      });
    document
      .querySelector("#register-form")
      ?.addEventListener("submit", (event) => {
        event.preventDefault();
        submitAuth(event.currentTarget, "register");
      });
  }

  async function initEditor() {
    await loadIdentity();
    const form = document.querySelector("#article-form");
    if (!form || isFilePreview) return;
    try {
      const state = await api("/auth");
      csrfToken = state.csrf;
      currentUser = state.user;
      if (!currentUser || !["author", "admin"].includes(currentUser.role))
        throw new Error("Для работы в редакторе нужна роль автора.");
      const categories = await api("/categories");
      const select = document.querySelector("#category-select");
      for (const item of categories.data) {
        const option = document.createElement("option");
        option.value = item.id;
        option.textContent = item.name;
        select.append(option);
      }
      const id = new URLSearchParams(location.search).get("id");
      if (id) {
        if (!["author", "admin"].includes(currentUser.role))
          throw new Error(
            "Редактирование доступно автору собственных возвращённых материалов или администратору.",
          );
        const draft = await api(`/edit-article?id=${encodeURIComponent(id)}`);
        for (const field of [
          "title",
          "subtitle",
          "category_id",
          "tags",
          "source_url",
          "image_url",
        ])
          form.elements[field].value = draft[field] || "";
        document.querySelector("#article-id").value = draft.id;
        document.querySelector("#editor-heading").textContent =
          "Редактировать статью";
        document.querySelector("#editor-content").innerHTML = draft.body;
      }
    } catch (error) {
      showNotice(error.message, "error");
    }
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const values = Object.fromEntries(new FormData(form).entries());
      values.body = document.querySelector("#editor-content").innerHTML;
      try {
        await api("/actions", {
          method: "POST",
          body: JSON.stringify({
            ...values,
            action: "publish",
            csrf: csrfToken,
          }),
        });
        showNotice(
          currentUser.role === "admin"
            ? "Статья сохранена."
            : "Статья отправлена модератору.",
        );
        window.setTimeout(() => {
          window.location.href = fileUrl("/dashboard");
        }, 800);
      } catch (error) {
        showNotice(error.message, "error");
      }
    });
  }

  function bindArticleActions() {
    document
      .querySelectorAll(".rating-star")
      .forEach((star) => {
        const preview = () =>
          paintRatingStars(Number(star.dataset.value), currentRating);
        star.addEventListener("mouseenter", preview);
        star.addEventListener("focus", preview);
        star.addEventListener("click", async () => {
          try {
            const value = Number(star.dataset.value);
            await api("/actions", {
              method: "POST",
              body: JSON.stringify({
                action: "rate",
                article_id: window.currentArticleId,
                value,
                csrf: csrfToken,
              }),
            });
            currentRating = value;
            paintRatingStars(currentRating, currentRating);
            showNotice("Оценка сохранена.");
            loadArticle();
          } catch (error) {
            showNotice(error.message, "error");
          }
        });
      });
    document.querySelector("#rating-stars")?.addEventListener("mouseleave", () => {
      paintRatingStars(currentRating, currentRating);
    });
    document.querySelectorAll(".follow-button").forEach((button) =>
      button.addEventListener("click", async () => {
        try {
          await api("/actions", {
            method: "POST",
            body: JSON.stringify({
              action: "follow",
              target_type: button.dataset.followType,
              target_id: button.dataset.targetId,
              csrf: csrfToken,
            }),
          });
          loadArticle();
        } catch (error) {
          showNotice(error.message, "error");
        }
      }),
    );
    document
      .querySelector("#comment-form")
      ?.addEventListener("submit", async (event) => {
        event.preventDefault();
        try {
          await api("/actions", {
            method: "POST",
            body: JSON.stringify({
              action: "comment",
              article_id: window.currentArticleId,
              body: document.querySelector("#comment").value,
              csrf: csrfToken,
            }),
          });
          document.querySelector("#comment").value = "";
          showNotice("Комментарий опубликован.");
          loadArticle();
        } catch (error) {
          showNotice(error.message, "error");
        }
      });
  }

  function bindEditorToolbar() {
    document.querySelectorAll("[data-editor-command]").forEach((button) =>
      button.addEventListener("click", () => {
        document.execCommand(
          button.dataset.editorCommand,
          false,
          button.dataset.editorValue || null,
        );
        document.querySelector("#editor-content")?.focus();
      }),
    );
  }

  async function hydrateSharedShell() {
    if (isFilePreview) return;
    // ссылки в подвале, пока не надо
    // for (const [selector, url] of [[".site-footer", "/partials/footer.html"]]) {
    //   try {
    //     const response = await fetch(url, { credentials: "same-origin" });
    //     if (!response.ok) continue;
    //     const parsed = new DOMParser().parseFromString(
    //       await response.text(),
    //       "text/html",
    //     );
    //     const shared = parsed.body.firstElementChild;
    //     document.querySelector(selector)?.replaceWith(shared);
    //   } catch {
    //   }
    // }
  }

  async function start() {
    const cachedUser = cacheRead("user");
    if (cachedUser) renderAccountActions(cachedUser);
    await hydrateSharedShell();
    ensureHeaderTools();
    document.addEventListener("click", (event) => {
      if (event.target.closest(".theme-toggle")) {
        document.body.classList.toggle("dark");
        localStorage.setItem(
          "digest-theme",
          document.body.classList.contains("dark") ? "dark" : "light",
        );
      }
      if (event.target.closest(".random-article")) {
        loadRandomArticle();
      }
    });
    if (
      localStorage.getItem("digest-theme") === "dark" ||
      (!localStorage.getItem("digest-theme") &&
        matchMedia("(prefers-color-scheme: dark)").matches)
    )
      document.body.classList.add("dark");
    if (page === "feed") {
      document
        .querySelector("#sort-form")
        ?.addEventListener("change", loadFeed);
      loadIdentity();
      loadFeed();
    } else if (page === "auth") initAuth();
    else if (page === "article") {
      loadIdentity();
      loadArticle();
      bindArticleActions();
    } else if (page === "tag") {
      loadIdentity();
      loadTag();
    } else if (page === "profile") {
      loadIdentity().then(loadProfile);
    } else if (page === "subscriptions") {
      loadIdentity();
      loadSubscriptions();
      document.querySelector("#sort-form")?.addEventListener("change", loadSubscriptions);
    } else if (page === "dashboard") {
      loadIdentity();
      loadDashboard();
    } else if (page === "editor") {
      bindEditorToolbar();
      initEditor();
    } else if (page === "settings") {
      initSettings();
    }
  }

  window.addEventListener("pageshow", (event) => {
    if (!event.persisted || isFilePreview) return;
    loadIdentity().then((state) => {
      const addAccountMode = new URLSearchParams(window.location.search).get("add-account") === "1";
      if (page === "auth" && state?.user && !addAccountMode) {
        window.location.replace(fileUrl("/dashboard"));
      } else if (!state?.user) {
        return;
      } else if (page === "feed") loadFeed();
      else if (page === "article") loadArticle();
      else if (page === "tag") loadTag();
      else if (page === "profile") loadProfile();
      else if (page === "subscriptions") loadSubscriptions();
      else if (page === "dashboard") loadDashboard();
      else if (page === "settings" || page === "editor") window.location.reload();
    });
  });

  function ensureHeaderTools() {
    const nav = document.querySelector(".header-actions");
    if (!nav || nav.querySelector(".random-article")) return;
    const button = textElement("button", "Случайная статья", "header-tool-button random-article");
    button.type = "button";
    const rss = nav.querySelector(".rss-link");
    nav.insertBefore(button, rss || nav.firstChild);
  }

  start();
})();