const { createApp } = Vue;

createApp({
  data() {
    return {
      query: {
        title: '',
        titleMode: 'keyword',
        author: '',
        authorMode: 'keyword',
        fromYear: '1999',
        fromMonth: '1',
        toYear: '',
        toMonth: '',
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
  },
  watch: {
    query: {
      handler() {
        this.scheduleSearch();
      },
      deep: true,
    },
  },
  mounted() {
    this.runSearch(true);
  },
  methods: {
    scheduleSearch() {
      if (this.debounceTimer) {
        clearTimeout(this.debounceTimer);
      }
      this.debounceTimer = setTimeout(() => {
        this.runSearch();
      }, 500);
    },
    buildParams() {
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
      params.set('limit', '200');
      params.set('offset', '0');
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
        const response = await fetch(`public/api/search?${params.toString()}`, {
          signal: this.aborter.signal,
        });
        const data = await response.json();
        this.items = data.items || [];
        this.meta = data.meta || { total: 0, returned: 0 };
        this.errors = data.errors || [];
      } catch (error) {
        if (error.name !== 'AbortError') {
          this.errors = ['API通信に失敗しました。'];
        }
      } finally {
        this.loading = false;
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
    pdfOffset(year, month) {
      const offsets = this.meta.pdf_offsets || {};
      const fallback = Number.isFinite(this.meta.pdf_offset_default) ? this.meta.pdf_offset_default : 0;
      if (!year || !month) {
        return fallback;
      }
      const key = `${year}-${String(month).padStart(2, '0')}`;
      const value = offsets[key];
      return Number.isFinite(value) ? value : fallback;
    },
    pdfLink(year, month, startPage) {
      if (!year || !month) {
        return '';
      }
      const ym = `${year}${String(month).padStart(2, '0')}`;
      const base = this.meta.pdf_base || '/tr-book';
      const page = parseInt(startPage, 10);
      const offset = this.pdfOffset(year, month);
      const target = Number.isFinite(page) ? page + offset : NaN;
      const pageAnchor = Number.isFinite(target) && target > 0 ? `#page=${target}` : '';
      return `${base}/${year}/TR${ym}.PDF${pageAnchor}`;
    },
  },
}).mount('#app');
