(() => {
  const sidebarStates = new WeakMap();

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

    const collapseButton = event.target.closest('[data-role-shell-collapse]');
    if (!collapseButton) return;

    const shell = collapseButton.closest('[data-role-shell]');
    const sidebarState = shell ? sidebarStates.get(shell) : null;
    if (!sidebarState) return;

    event.preventDefault();
    event.stopPropagation();
    sidebarState.toggle();
  }, true);

  document.querySelectorAll('[data-role-shell]').forEach((shell) => {
    const role = shell.dataset.roleShell;
    const sidebar = shell.querySelector('[data-role-shell-sidebar]');
    const collapseButton = shell.querySelector('[data-role-shell-collapse]');
    const searchInput = shell.querySelector('[data-role-shell-search]');
    const searchContainer = searchInput?.closest(`.${role}-topbar-search`);
    const searchSuggestions = shell.querySelector('[data-role-shell-suggestions]');
    const navigation = shell.querySelector('[data-role-shell-nav]');
    const topbar = shell.querySelector('[data-role-shell-topbar]');

    if (sidebar && collapseButton) {
      const storageKey = `rms-${role}-sidebar-collapsed`;
      let desktopCollapsed = false;
      const isMobile = () => window.matchMedia('(max-width: 768px)').matches;

      const setCollapsed = (collapsed, persist = true) => {
        desktopCollapsed = collapsed;
        shell.classList.toggle('is-collapsed', collapsed && !isMobile());
        collapseButton.setAttribute('aria-label', desktopCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapseButton.setAttribute('aria-expanded', desktopCollapsed ? 'false' : 'true');
        collapseButton.title = desktopCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
        collapseButton.querySelector('span').textContent = desktopCollapsed ? '›' : '‹';

        if (persist) {
          try {
            localStorage.setItem(storageKey, desktopCollapsed ? '1' : '0');
          } catch (error) {
            // Storage may be disabled.
          }
        }
      };

      try {
        setCollapsed(localStorage.getItem(storageKey) === '1', false);
      } catch (error) {
        setCollapsed(false, false);
      }

      sidebarStates.set(shell, {
        toggle() {
          if (!isMobile()) setCollapsed(!desktopCollapsed);
        }
      });
      window.addEventListener('resize', () => setCollapsed(desktopCollapsed, false));
      sidebar.querySelectorAll('details').forEach((group) => {
        group.addEventListener('toggle', () => {
          if (desktopCollapsed && group.open && !isMobile()) setCollapsed(false);
        });
      });
    }

    if (!searchInput || !searchContainer || !searchSuggestions || !navigation || !topbar) return;

    searchSuggestions.classList.add('role-shell-search-suggestions');
    document.body.appendChild(searchSuggestions);
    const pageLinks = Array.from(navigation.querySelectorAll('a[href]'))
      .filter((link) => {
        const href = link.getAttribute('href')?.trim() || '';
        return href !== ''
          && href !== '#'
          && !/^javascript:/i.test(href)
          && !link.hasAttribute('data-todo')
          && !link.closest('form')
          && link.getAttribute('aria-disabled') !== 'true';
      })
      .map((link) => {
        const label = link.querySelector(`.${role}-nav-label`)?.textContent.trim() || link.textContent.trim();
        const group = link.closest(`.${role}-nav-group`);
        const section = group?.querySelector(
          `.${role}-nav-group-summary .${role}-nav-label, .${role}-nav-group-link .${role}-nav-label`
        )?.textContent.trim() || 'Main';
        return {
          href: link.href,
          label,
          section,
          searchText: `${label} ${section}`.toLocaleLowerCase()
        };
      })
      .filter((page) => page.label !== '');
    let activeSuggestion = -1;

    const closeSuggestions = () => {
      searchSuggestions.hidden = true;
      searchSuggestions.replaceChildren();
      searchInput.setAttribute('aria-expanded', 'false');
      searchInput.removeAttribute('aria-activedescendant');
      activeSuggestion = -1;
    };

    const positionSuggestions = () => {
      if (searchSuggestions.hidden) return;
      const inputBounds = searchInput.getBoundingClientRect();
      const topbarBounds = topbar.getBoundingClientRect();
      const width = Math.min(inputBounds.width, window.innerWidth - 16);
      const left = Math.max(8, Math.min(inputBounds.left, window.innerWidth - width - 8));
      searchSuggestions.style.left = `${left}px`;
      searchSuggestions.style.top = `${Math.max(inputBounds.bottom, topbarBounds.bottom) + 8}px`;
      searchSuggestions.style.width = `${width}px`;
    };

    const renderSuggestions = () => {
      const query = searchInput.value.trim().toLocaleLowerCase();
      searchSuggestions.replaceChildren();
      activeSuggestion = -1;
      if (!query) {
        closeSuggestions();
        return;
      }

      const matches = pageLinks
        .filter((page) => page.searchText.includes(query))
        .sort((first, second) => {
          const firstStarts = first.label.toLocaleLowerCase().startsWith(query) ? 0 : 1;
          const secondStarts = second.label.toLocaleLowerCase().startsWith(query) ? 0 : 1;
          return firstStarts - secondStarts || first.label.localeCompare(second.label);
        })
        .slice(0, 8);

      if (!matches.length) {
        const emptyMessage = document.createElement('div');
        emptyMessage.className = `role-shell-search-empty ${role}-search-empty`;
        emptyMessage.textContent = 'No matching pages found.';
        searchSuggestions.appendChild(emptyMessage);
      } else {
        matches.forEach((page, index) => {
          const link = document.createElement('a');
          link.className = `role-shell-search-suggestion ${role}-search-suggestion`;
          link.id = `${role}-search-option-${index}`;
          link.href = page.href;
          link.setAttribute('role', 'option');
          link.setAttribute('aria-selected', 'false');

          const label = document.createElement('span');
          label.className = 'role-shell-search-suggestion-label';
          label.textContent = page.label;
          const section = document.createElement('span');
          section.className = 'role-shell-search-suggestion-section';
          section.textContent = page.section;
          link.append(label, section);
          searchSuggestions.appendChild(link);
        });
      }

      searchSuggestions.hidden = false;
      searchInput.setAttribute('aria-expanded', 'true');
      positionSuggestions();
    };

    const setActiveSuggestion = (index) => {
      const options = searchSuggestions.querySelectorAll('[role="option"]');
      if (!options.length) return;
      activeSuggestion = (index + options.length) % options.length;
      options.forEach((option, optionIndex) => {
        option.setAttribute('aria-selected', optionIndex === activeSuggestion ? 'true' : 'false');
      });
      searchInput.setAttribute('aria-activedescendant', options[activeSuggestion].id);
    };

    searchInput.addEventListener('input', renderSuggestions);
    searchInput.addEventListener('focus', renderSuggestions);
    searchInput.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') {
        if (searchSuggestions.hidden) renderSuggestions();
        setActiveSuggestion(activeSuggestion + 1);
        event.preventDefault();
      } else if (event.key === 'ArrowUp' && !searchSuggestions.hidden) {
        setActiveSuggestion(activeSuggestion <= 0
          ? searchSuggestions.querySelectorAll('[role="option"]').length - 1
          : activeSuggestion - 1);
        event.preventDefault();
      } else if (event.key === 'Enter' && !searchSuggestions.hidden) {
        const options = searchSuggestions.querySelectorAll('[role="option"]');
        const destination = options[activeSuggestion >= 0 ? activeSuggestion : 0];
        if (destination) {
          destination.click();
          event.preventDefault();
        }
      } else if (event.key === 'Escape') {
        closeSuggestions();
      }
    });

    document.addEventListener('click', (event) => {
      if (!searchContainer.contains(event.target) && !searchSuggestions.contains(event.target)) {
        closeSuggestions();
      }
    });
    window.addEventListener('resize', () => window.requestAnimationFrame(positionSuggestions));
    window.addEventListener('scroll', positionSuggestions, true);
    if ('ResizeObserver' in window) {
      new ResizeObserver(positionSuggestions).observe(searchInput);
    }
  });
})();
