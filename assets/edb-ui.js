(function(){
  function parseJSON(value, fallback){
    try { return JSON.parse(value || ''); } catch (e) { return fallback; }
  }

  function storageKey(root, suffix){
    return 'edb_message_center_' + suffix;
  }

  function tokenList(value){
    return String(value || '').toLowerCase().split('|').map(function(v){ return v.trim(); }).filter(Boolean);
  }

  function includesToken(value, needle){
    if(!needle) return true;
    return tokenList(value).indexOf(String(needle).toLowerCase()) !== -1;
  }

  function includesAny(value, needles){
    if(!needles || !needles.length) return true;
    var hay = tokenList(value);
    return needles.some(function(n){ return hay.indexOf(String(n).toLowerCase()) !== -1; });
  }

  function containsText(value, needle){
    if(!needle) return true;
    return String(value || '').toLowerCase().indexOf(String(needle).toLowerCase()) !== -1;
  }

  function selectedCheckboxValues(root, attr){
    return Array.prototype.slice.call(root.querySelectorAll('input[' + attr + ']:checked')).map(function(el){
      return String(el.value || '').toLowerCase();
    }).filter(Boolean);
  }

  function initMessageCenter(root){
    var table = root.querySelector('[data-edb-directory-table]');
    if(!table) return;
    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('[data-edb-row]'));
    var selectAll = table.querySelector('[data-edb-select-all]');
    var viewButtons = Array.prototype.slice.call(root.querySelectorAll('[data-edb-set-view]'));
    var sortState = { key: '', dir: 'asc' };
    var defaultWp = parseJSON(root.getAttribute('data-edb-default-columns-wp'), ['name','job_title','division','job_path']);
    var defaultDiscord = parseJSON(root.getAttribute('data-edb-default-columns-discord'), ['name','discord_account','discord_roles','guilds','employment_status']);
    var savedView = localStorage.getItem(storageKey(root, 'view')) || 'wordpress';
    var visibleByView = parseJSON(localStorage.getItem(storageKey(root, 'columns')), {});
    if(!visibleByView.wordpress) visibleByView.wordpress = defaultWp.slice();
    if(!visibleByView.discord) visibleByView.discord = defaultDiscord.slice();
    var currentView = savedView === 'discord' ? 'discord' : 'wordpress';

    function getColumnHeaders(){
      return Array.prototype.slice.call(table.querySelectorAll('thead [data-edb-col]'));
    }

    function setModalState(name, open){
      var modal = root.querySelector('[data-edb-modal="' + name + '"]');
      if(!modal) return;
      if(open){
        modal.hidden = false;
        document.body.classList.add('edb-modal-open');
      } else {
        modal.hidden = true;
        if(!root.querySelector('.edb-modal:not([hidden])')){
          document.body.classList.remove('edb-modal-open');
        }
      }
    }

    function syncSelectionState(){
      var selectedRows = rows.filter(function(row){
        var cb = row.querySelector('[data-edb-row-select]');
        return cb && cb.checked;
      });
      var selectedCount = selectedRows.length;
      var portalIds = [];
      var discordIds = [];
      selectedRows.forEach(function(row){
        var uid = String(row.getAttribute('data-edb-user-id') || '');
        var did = String(row.getAttribute('data-edb-discord-id') || '');
        if(uid && uid !== '0') portalIds.push(uid);
        if(did) discordIds.push(did);
      });
      root.querySelectorAll('[data-edb-selected-count]').forEach(function(node){
        node.textContent = String(selectedCount);
      });
      root.querySelectorAll('[data-edb-selected-summary]').forEach(function(node){
        node.textContent = selectedCount ? (selectedCount + ' selected • ' + portalIds.length + ' portal user(s) • ' + discordIds.length + ' Discord account(s)') : 'Choose one or more people in the table first.';
      });
      root.querySelectorAll('form[data-edb-selection-form]').forEach(function(form){
        var userInput = form.querySelector('input[name="edb_selected_user_ids"]');
        var discordInput = form.querySelector('input[name="edb_selected_discord_ids"]');
        if(userInput) userInput.value = portalIds.join(',');
        if(discordInput) discordInput.value = discordIds.join(',');
      });
      var visibleRows = rows.filter(function(row){ return !row.hidden; });
      var visibleSelected = visibleRows.filter(function(row){
        var cb = row.querySelector('[data-edb-row-select]');
        return cb && cb.checked;
      }).length;
      if(selectAll){
        selectAll.checked = visibleRows.length > 0 && visibleSelected === visibleRows.length;
        selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visibleRows.length;
      }
    }

    function applyColumnSet(columns){
      visibleByView[currentView] = columns.slice();
      localStorage.setItem(storageKey(root, 'columns'), JSON.stringify(visibleByView));
      var visible = {};
      columns.forEach(function(col){ visible[col] = true; });
      root.querySelectorAll('[data-edb-col]').forEach(function(cell){
        var key = cell.getAttribute('data-edb-col');
        cell.classList.toggle('is-hidden', !visible[key]);
      });
      root.querySelectorAll('[data-edb-column-toggle]').forEach(function(cb){
        cb.checked = !!visible[cb.getAttribute('data-edb-column-toggle')];
      });
    }

    function applyPreset(name){
      applyColumnSet((name === 'discord' ? defaultDiscord : defaultWp).slice());
    }

    function setView(view){
      currentView = view === 'discord' ? 'discord' : 'wordpress';
      localStorage.setItem(storageKey(root, 'view'), currentView);
      root.setAttribute('data-edb-view', currentView);
      viewButtons.forEach(function(btn){
        var active = btn.getAttribute('data-edb-set-view') === currentView;
        btn.classList.toggle('button-primary', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      root.querySelectorAll('[data-edb-filter-scope]').forEach(function(scope){
        scope.hidden = scope.getAttribute('data-edb-filter-scope') !== currentView;
      });
      applyColumnSet(visibleByView[currentView] || (currentView === 'discord' ? defaultDiscord : defaultWp));
      applyFilters();
    }

    function getActiveFilters(){
      if(currentView === 'discord'){
        return {
          search: (root.querySelector('[data-edb-filter-scope="discord"] [data-edb-filter-input="search"]') || {}).value || '',
          hasRole: (root.querySelector('[data-edb-filter-scope="discord"] [data-edb-filter-input="discord-role-has"]') || {}).value || '',
          notRole: (root.querySelector('[data-edb-filter-scope="discord"] [data-edb-filter-input="discord-role-not"]') || {}).value || '',
          source: (root.querySelector('[data-edb-filter-scope="discord"] [data-edb-filter-input="source"]') || {}).value || ''
        };
      }
      return {
        search: (root.querySelector('[data-edb-filter-scope="wordpress"] [data-edb-filter-input="search"]') || {}).value || '',
        wpRole: (root.querySelector('[data-edb-filter-scope="wordpress"] [data-edb-filter-input="wp-role"]') || {}).value || '',
        employmentStatus: (root.querySelector('[data-edb-filter-scope="wordpress"] [data-edb-filter-input="employment-status"]') || {}).value || '',
        linked: (root.querySelector('[data-edb-filter-scope="wordpress"] [data-edb-filter-input="linked"]') || {}).value || '',
        jobPaths: selectedCheckboxValues(root.querySelector('[data-edb-filter-scope="wordpress"]') || root, 'data-edb-filter-checkbox="job-path"'),
        locations: selectedCheckboxValues(root.querySelector('[data-edb-filter-scope="wordpress"]') || root, 'data-edb-filter-checkbox="location"'),
        groups: selectedCheckboxValues(root.querySelector('[data-edb-filter-scope="wordpress"]') || root, 'data-edb-filter-checkbox="group"'),
        projects: selectedCheckboxValues(root.querySelector('[data-edb-filter-scope="wordpress"]') || root, 'data-edb-filter-checkbox="project"')
      };
    }

    function applyFilters(){
      var filters = getActiveFilters();
      rows.forEach(function(row){
        var show = true;
        if(currentView === 'discord'){
          show = show && containsText(row.getAttribute('data-edb-search'), filters.search);
          show = show && includesToken(row.getAttribute('data-edb-discord-role'), filters.hasRole);
          show = show && (!filters.notRole || !includesToken(row.getAttribute('data-edb-discord-role'), filters.notRole));
          if(filters.source){
            show = show && row.getAttribute('data-edb-source') === filters.source;
          }
        } else {
          show = show && containsText(row.getAttribute('data-edb-search'), filters.search);
          show = show && includesToken(row.getAttribute('data-edb-wp-role'), filters.wpRole);
          show = show && includesToken(row.getAttribute('data-edb-employment-status'), filters.employmentStatus);
          show = show && includesToken(row.getAttribute('data-edb-linked'), filters.linked);
          show = show && includesAny(row.getAttribute('data-edb-job-path'), filters.jobPaths);
          show = show && includesAny(row.getAttribute('data-edb-location'), filters.locations);
          show = show && includesAny(row.getAttribute('data-edb-group'), filters.groups);
          show = show && includesAny(row.getAttribute('data-edb-project'), filters.projects);
        }
        row.hidden = !show;
      });
      syncSelectionState();
    }

    function sortRows(key){
      if(sortState.key === key){
        sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
      } else {
        sortState.key = key;
        sortState.dir = 'asc';
      }
      var factor = sortState.dir === 'asc' ? 1 : -1;
      rows.sort(function(a,b){
        var aCell = a.querySelector('[data-edb-col="' + key + '"]');
        var bCell = b.querySelector('[data-edb-col="' + key + '"]');
        var av = aCell ? (aCell.getAttribute('data-edb-sort-value') || aCell.textContent || '') : '';
        var bv = bCell ? (bCell.getAttribute('data-edb-sort-value') || bCell.textContent || '') : '';
        return av.localeCompare(bv, undefined, {numeric:true, sensitivity:'base'}) * factor;
      });
      rows.forEach(function(row){ tbody.appendChild(row); });
      getColumnHeaders().forEach(function(head){
        var dir = head.getAttribute('data-edb-sort') === sortState.key ? sortState.dir : 'none';
        head.setAttribute('data-edb-sort-dir', dir);
      });
    }

    root.addEventListener('click', function(e){
      var openBtn = e.target.closest('[data-edb-open-modal]');
      if(openBtn){
        e.preventDefault();
        setModalState(openBtn.getAttribute('data-edb-open-modal'), true);
        syncSelectionState();
        return;
      }
      var closeBtn = e.target.closest('[data-edb-close-modal]');
      if(closeBtn){
        e.preventDefault();
        setModalState(closeBtn.getAttribute('data-edb-close-modal'), false);
        return;
      }
      var sortHead = e.target.closest('[data-edb-sort]');
      if(sortHead && !sortHead.classList.contains('is-hidden')){
        e.preventDefault();
        sortRows(sortHead.getAttribute('data-edb-sort'));
        return;
      }
      var preset = e.target.closest('[data-edb-apply-column-preset]');
      if(preset){
        e.preventDefault();
        applyPreset(preset.getAttribute('data-edb-apply-column-preset'));
        return;
      }
      var reset = e.target.closest('[data-edb-reset-filters]');
      if(reset){
        e.preventDefault();
        root.querySelectorAll('[data-edb-filter-input]').forEach(function(input){ input.value = ''; });
        root.querySelectorAll('[data-edb-filter-checkbox]').forEach(function(cb){ cb.checked = false; });
        applyFilters();
        return;
      }
    });

    root.addEventListener('change', function(e){
      if(e.target.matches('[data-edb-row-select]')){
        syncSelectionState();
        return;
      }
      if(e.target.matches('[data-edb-column-toggle]')){
        var selected = Array.prototype.slice.call(root.querySelectorAll('[data-edb-column-toggle]:checked')).map(function(cb){
          return cb.getAttribute('data-edb-column-toggle');
        });
        if(!selected.length){
          e.target.checked = true;
          return;
        }
        applyColumnSet(selected);
        return;
      }
      if(e.target.matches('[data-edb-filter-input], [data-edb-filter-checkbox]')){
        applyFilters();
        return;
      }
    });

    root.addEventListener('input', function(e){
      if(e.target.matches('[data-edb-filter-input]')){
        applyFilters();
      }
    });

    if(selectAll){
      selectAll.addEventListener('change', function(){
        rows.forEach(function(row){
          if(row.hidden) return;
          var cb = row.querySelector('[data-edb-row-select]');
          if(cb) cb.checked = !!selectAll.checked;
        });
        syncSelectionState();
      });
    }

    viewButtons.forEach(function(btn){
      btn.addEventListener('click', function(){
        setView(btn.getAttribute('data-edb-set-view'));
      });
    });

    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape'){
        root.querySelectorAll('.edb-modal').forEach(function(modal){ modal.hidden = true; });
        document.body.classList.remove('edb-modal-open');
      }
    });

    setView(currentView);
    sortRows('name');
    syncSelectionState();
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-edb-message-center="1"]').forEach(initMessageCenter);
  });
})();
