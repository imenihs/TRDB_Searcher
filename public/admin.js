const { createApp } = Vue;

createApp({
  data() {
    return {
      auth: {
        loggedIn: false,
        user: '',
        showLogin: false,
        username: '',
        password: '',
        error: '',
        isAdmin: false,
      },
      admin: {
        loading: false,
        error: '',
        users: [],
        showDialog: false,
        dialogMode: 'add',
        dialogTitle: 'ユーザ追加',
        dialogAction: '追加',
        dialogError: '',
        showDeleteConfirm: false,
        deleteTarget: '',
        editTarget: null,
        form: {
          username: '',
          password: '',
          confirmPassword: '',
          is_admin: false,
        },
      },
    };
  },
  mounted() {
    this.fetchAuth();
  },
  methods: {
    translateError(message) {
      const map = {
        "Forbidden.": "権限がありません。",
        "User not found.": "ユーザが見つかりません。",
        "Username already exists.": "ユーザ名は既に存在します。",
        "Username and password required.": "ユーザ名とパスワードを入力してください。",
        "Failed to save users.": "ユーザ保存に失敗しました。",
        "Invalid credentials.": "ユーザー名またはパスワードが違います。",
        "Cannot delete last admin.": "最後の管理者は削除できません。",
        "Cannot delete yourself.": "自分自身は削除できません。",
      };
      return map[message] || message || "エラーが発生しました。";
    },
    async fetchAuth() {
      try {
        const res = await fetch('api/me', { credentials: 'same-origin' });
        const data = await res.json();
        this.auth.loggedIn = !!data.logged_in;
        this.auth.user = data.user || '';
        this.auth.isAdmin = !!data.is_admin;
        if (this.auth.isAdmin) {
          await this.fetchUsers();
        }
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
          this.auth.error = this.translateError(data.error) || 'ログインに失敗しました。';
          return;
        }
        this.auth.loggedIn = true;
        this.auth.user = data.user || this.auth.username;
        this.auth.isAdmin = !!data.is_admin;
        this.auth.password = '';
        this.auth.showLogin = false;
        if (this.auth.isAdmin) {
          await this.fetchUsers();
        }
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
    async fetchUsers() {
      this.admin.loading = true;
      this.admin.error = '';
      try {
        const res = await fetch('api/admin/users', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.ok) {
          this.admin.error = this.translateError(data.error) || 'ユーザ一覧の取得に失敗しました。';
          return;
        }
        this.admin.users = (data.users || []).map((user) => ({
          ...user,
        }));
      } catch (e) {
        this.admin.error = 'ユーザ一覧の取得に失敗しました。';
      } finally {
        this.admin.loading = false;
      }
    },
    openAdd() {
      this.admin.dialogMode = 'add';
      this.admin.dialogTitle = 'ユーザ追加';
      this.admin.dialogAction = '追加';
      this.admin.editTarget = null;
      this.admin.form.username = '';
      this.admin.form.password = '';
      this.admin.form.confirmPassword = '';
      this.admin.form.is_admin = false;
      this.admin.dialogError = '';
      this.admin.error = '';
      this.admin.showDialog = true;
    },
    openEdit(user) {
      this.admin.dialogMode = 'edit';
      this.admin.dialogTitle = 'ユーザ更新';
      this.admin.dialogAction = '更新';
      this.admin.editTarget = user.username;
      this.admin.form.username = user.username;
      this.admin.form.password = '';
      this.admin.form.confirmPassword = '';
      this.admin.form.is_admin = !!user.is_admin;
      this.admin.dialogError = '';
      this.admin.error = '';
      this.admin.showDialog = true;
    },

    async deleteUser(user) {
      this.admin.error = '';
      this.admin.deleteTarget = user.username;
      this.admin.showDeleteConfirm = true;
    },
    cancelDelete() {
      this.admin.showDeleteConfirm = false;
      this.admin.deleteTarget = '';
    },
    async confirmDelete() {
      this.admin.error = '';
      const target = this.admin.deleteTarget;
      if (!target) {
        return;
      }
      try {
        const res = await fetch(`api/admin/users/${encodeURIComponent(target)}`, {
          method: 'DELETE',
          credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.ok) {
          this.admin.error = this.translateError(data.error) || 'ユーザ削除に失敗しました。';
          return;
        }
        this.admin.showDeleteConfirm = false;
        this.admin.deleteTarget = '';
        await this.fetchUsers();
      } catch (e) {
        this.admin.error = 'ユーザ削除に失敗しました。';
      }
    },
    closeDialog() {
      this.admin.showDialog = false;
    },
    async submitDialog() {
      this.admin.dialogError = '';
      const { username, password, confirmPassword, is_admin } = this.admin.form;
      if (!username) {
        this.admin.dialogError = 'ユーザ名を入力してください。';
        return;
      }
      if (this.admin.dialogMode === 'add') {
        if (!password || !confirmPassword) {
          this.admin.dialogError = 'パスワードを2回入力してください。';
          return;
        }
        if (password != confirmPassword) {
          this.admin.dialogError = 'パスワードが一致しません。';
          return;
        }
      } else if (password || confirmPassword) {
        if (!password || !confirmPassword) {
          this.admin.dialogError = 'パスワードを2回入力してください。';
          return;
        }
        if (password != confirmPassword) {
          this.admin.dialogError = 'パスワードが一致しません。';
          return;
        }
      }
      try {
        const body = new URLSearchParams();
        body.set('username', username);
        if (password) {
          body.set('password', password);
        }
        if (is_admin) {
          body.set('is_admin', '1');
        }
        if (this.admin.dialogMode === 'add') {
          const res = await fetch('api/admin/users', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
          });
          const data = await res.json();
          if (!data.ok) {
            this.admin.dialogError = this.translateError(data.error) || 'ユーザ追加に失敗しました。';
            return;
          }
        } else {
          const target = this.admin.editTarget || username;
          const res = await fetch(`api/admin/users/${encodeURIComponent(target)}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin',
          });
          const data = await res.json();
          if (!data.ok) {
            this.admin.dialogError = this.translateError(data.error) || 'ユーザ更新に失敗しました。';
            return;
          }
        }
        this.admin.showDialog = false;
        this.admin.showDeleteConfirm = false;
        this.admin.deleteTarget = '';
        await this.fetchUsers();
      } catch (e) {
        this.admin.dialogError = this.admin.dialogMode === 'add' ? 'ユーザ追加に失敗しました。' : 'ユーザ更新に失敗しました。';
      }
    },

  },
}).mount('#app');
