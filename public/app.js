function initApp() {
  const { createApp } = Vue;

  createApp({
  data() {
    return {
      query: {
        title: '',
        titleMode: 'keyword',
        author: '',
        authorMode: 'keyword',
        fromYear: '',
        fromMonth: '',
        toYear: '',
        toMonth: '',
        caseSensitive: false,
        wordMatch: false,
      },
      page: 1,
      pageInput: 1,
      pageSize: 100,
      currentYear: new Date().getFullYear(),
      viewMode: 'card',
      auth: {
        loggedIn: false,
        user: '',
        showLogin: false,
        username: '',
        password: '',
        error: '',
        isAdmin: false,
      },
      items: [],
      meta: {
        total: 0,
        returned: 0,
      },
      errors: [],
      loading: false,
      debounceTimer: null,
      aborter: null,
    };
  },
  computed: {
    lastDataLabel() {
      const lastData = this.meta.last_data;
      if (!lastData || !lastData.year || !lastData.month) {
        return '---';
      }
      return `${lastData.year}年${String(lastData.month).padStart(2, '0')}月`;
    },
    totalPages() {
      const total = Number(this.meta.total) || 0;
      return Math.max(1, Math.ceil(total / this.pageSize));
    },
    pagerPages() {
      const total = this.totalPages;
      if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
      }
      const current = this.page;
      let start = Math.max(2, current - 2);
      let end = Math.min(total - 1, start + 4);
      start = Math.max(2, end - 4);
      const pages = [1];
      for (let p = start; p <= end; p += 1) {
        pages.push(p);
      }
      pages.push(total);
      return pages;
    },
  },
  watch: {
    query: {
      handler() {
        this.page = 1;
        this.scheduleSearch();
      },
      deep: true,
    },
  },
  mounted() {
    this.fetchAuth();
    this.pageInput = this.page;
    this.runSearch(true);
  },
  methods: {
    async fetchAuth() {
      try {
        const res = await fetch('api/me', { credentials: 'same-origin' });
        const data = await res.json();
        this.auth.loggedIn = !!data.logged_in;
        this.auth.user = data.user || '';
        this.auth.isAdmin = !!data.is_admin;
      } catch (e) {
        this.auth.loggedIn = false;
        this.auth.user = '';
        this.auth.isAdmin = false;
      }
    },
    async login() {
      this.auth.error = '';
      if (!this.auth.username || !this.auth.password) {
        this.auth.error = 'ユーザー名とパスワードを入力してください。';
        return;
      }
      try {
        const body = new URLSearchParams();
        body.set('username', this.auth.username);
        body.set('password', this.auth.password);
        const res = await fetch('api/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.ok) {
          this.auth.error = data.error || 'ログインに失敗しました。';
          return;
        }
        this.auth.loggedIn = true;
        this.auth.user = data.user || this.auth.username;
        this.auth.isAdmin = !!data.is_admin;
        this.auth.password = '';
        this.auth.showLogin = false;
      } catch (e) {
        this.auth.error = 'ログインに失敗しました。';
      }
    },
    async logout() {
      try {
        await fetch('api/logout', { method: 'POST', credentials: 'same-origin' });
      } finally {
        this.auth.loggedIn = false;
        this.auth.user = '';
        this.auth.isAdmin = false;
      }
    },
    scheduleSearch() {
      if (this.debounceTimer) {
        clearTimeout(this.debounceTimer);
      }
      this.debounceTimer = setTimeout(() => {
        this.runSearch();
      }, 500);
    },
    buildParams(includePaging = true) {
      const params = new URLSearchParams();
      if (this.query.title.trim() !== '') {
        params.set('title', this.query.title.trim());
        params.set('title_mode', this.query.titleMode);
      }
      if (this.query.author.trim() !== '') {
        params.set('author', this.query.author.trim());
        params.set('author_mode', this.query.authorMode);
      }
      if (this.query.fromYear !== '') {
        params.set('from_year', this.query.fromYear);
      }
      if (this.query.fromMonth !== '') {
        params.set('from_month', this.query.fromMonth);
      }
      if (this.query.toYear !== '') {
        params.set('to_year', this.query.toYear);
      }
      if (this.query.toMonth !== '') {
        params.set('to_month', this.query.toMonth);
      }
      params.set('case_sensitive', this.query.caseSensitive ? '1' : '0');
      params.set('word_match', this.query.wordMatch ? '1' : '0');
      if (includePaging) {
        params.set('limit', String(this.pageSize));
        params.set('offset', String((this.page - 1) * this.pageSize));
      }
      return params;
    },
    async runSearch(force = false) {
      if (this.loading && !force) {
        return;
      }
      if (this.aborter) {
        this.aborter.abort();
      }
      this.aborter = new AbortController();
      this.loading = true;
      this.errors = [];

      try {
        const params = this.buildParams();
        const response = await fetch(`api/search?${params.toString()}`, {
          signal: this.aborter.signal,
        });
        const data = await response.json();
        this.items = (data.items || []).map((item) => ({
          ...item,
          file_exists: item.file_exists ?? false,
          file_name: item.file_name ?? null,
        }));
        this.meta = data.meta || { total: 0, returned: 0 };
        this.errors = data.errors || [];
        this.clampDateRange();

      } catch (error) {
        if (error.name !== 'AbortError') {
          this.errors = ['API通信に失敗しました。'];
        }
      } finally {
        this.loading = false;
      }
    },

    jumpToPage() {
      const target = Number(this.pageInput);
      if (!Number.isFinite(target)) {
        this.pageInput = this.page;
        return;
      }
      const clamped = Math.min(Math.max(1, target), this.totalPages);
      this.pageInput = clamped;
      this.goToPage(clamped);
    },

    clampDateRange() {
      const minData = this.meta.min_data;
      const maxData = this.meta.last_data;
      if (!minData || !maxData) {
        return false;
      }
      const minYm = minData.year * 100 + minData.month;
      const maxYm = maxData.year * 100 + maxData.month;
      let changed = false;

      const fromYear = Number(this.query.fromYear || 0);
      const fromMonth = Number(this.query.fromMonth || 0) || 1;
      const toYear = Number(this.query.toYear || 0);
      const toMonth = Number(this.query.toMonth || 0) || 12;

      if (fromYear) {
        const fromYm = fromYear * 100 + fromMonth;
        if (fromYm < minYm) {
          this.query.fromYear = String(minData.year);
          this.query.fromMonth = String(minData.month);
          changed = true;
        } else if (fromYm > maxYm) {
          this.query.fromYear = String(maxData.year);
          this.query.fromMonth = String(maxData.month);
          changed = true;
        }
      }

      if (toYear) {
        const toYm = toYear * 100 + toMonth;
        if (toYm > maxYm) {
          this.query.toYear = String(maxData.year);
          this.query.toMonth = String(maxData.month);
          changed = true;
        } else if (toYm < minYm) {
          this.query.toYear = String(minData.year);
          this.query.toMonth = String(minData.month);
          changed = true;
        }
      }

      return changed;
    },
    goToPage(page) {
      const target = Math.min(Math.max(1, page), this.totalPages);
      if (target === this.page) {
        return;
      }
      this.page = target;
      this.pageInput = target;
      this.runSearch(true);
    },
    async exportResults() {
      this.errors = [];
      try {
        const params = this.buildParams(false);
        const response = await fetch(`api/export?${params.toString()}`);
        if (!response.ok) {
          this.errors = ['エクスポートに失敗しました。'];
          return;
        }
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        const date = new Date();
        const stamp = `${date.getFullYear()}${String(date.getMonth() + 1).padStart(2, '0')}${String(date.getDate()).padStart(2, '0')}`;
        link.href = url;
        link.download = `tr_search_all_${stamp}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
      } catch (error) {
        this.errors = ['エクスポートに失敗しました。'];
      }
    },

    formatPages(startPage, pageCount) {
      const start = parseInt(startPage, 10);
      const count = parseInt(pageCount, 10);
      if (!Number.isFinite(start) || start <= 0) {
        return 'P.--- to ---';
      }
      const end = Number.isFinite(count) && count > 0 ? start + count : start;
      return `P.${start} to ${end}`;
    },
    formatDate(year, month) {
      if (!year || !month) {
        return '----/--';
      }
      return `${year}/${String(month).padStart(2, '0')}`;
    },
    fileOffset(year, month) {
      const offsets = this.meta.file_offsets || {};
      const fallback = Number.isFinite(this.meta.file_offset_default) ? this.meta.file_offset_default : 0;
      if (!year || !month) {
        return fallback;
      }
      const key = `${year}-${String(month).padStart(2, '0')}`;
      const value = offsets[key];
      return Number.isFinite(value) ? value : fallback;
    },
    fileLink(year, month, startPage, fileName) {
      if (!year || !month) {
        return '';
      }
      const ym = `${year}${String(month).padStart(2, '0')}`;
      const base = 'file.php';
      const page = parseInt(startPage, 10);
      const offset = this.fileOffset(year, month);
      const target = Number.isFinite(page) ? page + offset : NaN;
      const pageAnchor = Number.isFinite(target) && target > 0 ? `#page=${target}` : '';
      const nameParam = fileName ? `&name=${encodeURIComponent(fileName)}` : '';
      return `${base}?year=${year}&month=${month}${nameParam}${pageAnchor}`;
    },
  },
  }).mount('#app');
}

(function boot() {
  if (typeof window === 'undefined' || !window.Vue) {
    setTimeout(boot, 50);
    return;
  }
  initApp();
})();
