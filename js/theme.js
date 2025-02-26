// テーマの設定と管理を行うクラス
class ThemeManager {
    constructor() {
        this.STORAGE_KEY = 'themeColor';
        this.DEFAULT_COLOR = '#0d6efd';
        this.init();
    }

    init() {
        // 保存されているテーマカラーを読み込んで適用
        const savedColor = this.getSavedColor();
        this.applyTheme(savedColor);

        // DOMの読み込み完了後にイベントリスナーを設定
        document.addEventListener('DOMContentLoaded', () => {
            this.setupColorButtons();
            this.setupPageLoadHandlers();
        });
    }

    getSavedColor() {
        return localStorage.getItem(this.STORAGE_KEY) || this.DEFAULT_COLOR;
    }

    saveColor(color) {
        localStorage.setItem(this.STORAGE_KEY, color);
    }

    setupColorButtons() {
        // カラー選択ボタンのイベントリスナー設定
        document.querySelectorAll('.color-theme-btn').forEach(button => {
            button.addEventListener('click', () => {
                const color = button.dataset.color;
                this.updateActiveButton(button);
                this.applyTheme(color);
                this.saveColor(color);
            });

            // ホバーエフェクト
            this.setupButtonHoverEffect(button);
        });

        // 初期状態のアクティブボタンを設定
        const savedColor = this.getSavedColor();
        const activeButton = document.querySelector(`.color-theme-btn[data-color="${savedColor}"]`);
        if (activeButton) {
            this.updateActiveButton(activeButton);
        }
    }

    setupPageLoadHandlers() {
        // ページ遷移時やリロード時にもテーマを適用
        window.addEventListener('load', () => this.applyTheme(this.getSavedColor()));
        window.addEventListener('pageshow', () => this.applyTheme(this.getSavedColor()));
    }

    updateActiveButton(activeButton) {
        const color = activeButton.dataset.color;
        document.querySelectorAll('.color-theme-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.style.boxShadow = '';
        });
        activeButton.classList.add('active');
        activeButton.style.boxShadow = `0 0 0 2px #fff, 0 0 0 4px ${color}`;
    }

    setupButtonHoverEffect(button) {
        button.addEventListener('mouseenter', () => {
            if (!button.classList.contains('active')) {
                const color = button.dataset.color;
                button.style.boxShadow = `0 0 0 2px #fff, 0 0 0 4px ${color}`;
            }
        });

        button.addEventListener('mouseleave', () => {
            if (!button.classList.contains('active')) {
                button.style.boxShadow = '';
            }
        });
    }

    applyTheme(color) {
        if (!color) return;
        
        const rgb = this.hexToRgb(color);
        if (!rgb) return;

        // CSSカスタムプロパティの更新
        this.updateCSSVariables(color, rgb);
        
        // 動的な要素のスタイル更新
        this.updateDynamicElements(color);
    }

    updateCSSVariables(color, rgb) {
        const style = document.createElement('style');
        style.textContent = `
            :root {
                --theme-color: ${color};
                --theme-color-rgb: ${rgb.r}, ${rgb.g}, ${rgb.b};
                --theme-bg-light: ${this.adjustAlpha(color, 0.08)};
                --theme-hover: ${this.adjustBrightness(color, -10)};
            }
        `;

        const existingStyle = document.querySelector('style[data-theme-variables]');
        if (existingStyle) {
            existingStyle.remove();
        }

        style.setAttribute('data-theme-variables', '');
        document.head.appendChild(style);
    }

    updateDynamicElements(color) {
        // サイドバーの更新
        this.updateSidebarElements(color);
        
        // その他の要素の更新
        this.updateOtherElements(color);
    }

    updateSidebarElements(color) {
        // サイドバーの背景色
        document.querySelectorAll('.sidebar').forEach(sidebar => {
            sidebar.style.backgroundColor = this.adjustAlpha(color, 0.08);
        });

        // サイトタイトル
        document.querySelectorAll('.site-title').forEach(title => {
            title.style.color = color;
        });

        // ナビゲーションリンク
        document.querySelectorAll('.nav-link').forEach(link => {
            if (link.classList.contains('active')) {
                link.style.backgroundColor = color;
                link.style.color = '#ffffff';
            }
        });
    }

    updateOtherElements(color) {
        const rgb = this.hexToRgb(color);
        const rgbString = `${rgb.r}, ${rgb.g}, ${rgb.b}`;

        // カレンダーの曜日ヘッダー
        document.querySelectorAll('.calendar-table th').forEach(th => {
            th.style.borderBottomColor = color;
        });

        document.querySelectorAll('.weekdays').forEach(weekdays => {
            weekdays.style.borderBottomColor = color;
        });

        // ライブ一覧画面の要素を更新
        document.querySelectorAll('.calendar-header').forEach(header => {
            header.style.backgroundColor = this.adjustAlpha(color, 0.08);
        });

        document.querySelectorAll('.calendar-nav .btn').forEach(btn => {
            if (!btn.classList.contains('active')) {
                btn.style.color = color;
                btn.style.borderColor = color;
            }
        });

        document.querySelectorAll('.live-list-item').forEach(item => {
            item.style.borderLeftColor = color;
            item.addEventListener('mouseenter', () => {
                item.style.backgroundColor = this.adjustAlpha(color, 0.08);
            });
            item.addEventListener('mouseleave', () => {
                item.style.backgroundColor = '';
            });
        });

        document.querySelectorAll('.live-date-badge').forEach(badge => {
            badge.style.color = color;
            badge.style.borderColor = color;
            badge.style.backgroundColor = this.adjustAlpha(color, 0.08);
        });

        document.querySelectorAll('.filter-button').forEach(btn => {
            if (btn.classList.contains('active')) {
                btn.style.backgroundColor = color;
                btn.style.borderColor = color;
            } else {
                btn.addEventListener('mouseenter', () => {
                    btn.style.borderColor = color;
                    btn.style.color = color;
                });
                btn.addEventListener('mouseleave', () => {
                    btn.style.borderColor = '';
                    btn.style.color = '';
                });
            }
        });

        // テーマカラーのテキスト要素
        document.querySelectorAll('.theme-color').forEach(element => {
            element.style.color = color;
        });

        // セクションタイトルのボーダー
        document.querySelectorAll('.section-title.theme-color').forEach(title => {
            title.style.borderBottomColor = color;
        });

        // ホーム画面の要素を更新
        document.querySelectorAll('.upcoming-live').forEach(element => {
            element.style.borderLeftColor = color;
            element.style.backgroundColor = this.adjustAlpha(color, 0.08);
        });

        document.querySelectorAll('.live-date').forEach(element => {
            element.style.color = color;
        });

        document.querySelectorAll('.artist-card').forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.borderColor = color;
                card.style.boxShadow = `0 4px 8px ${this.adjustAlpha(color, 0.1)}`;
            });
            card.addEventListener('mouseleave', () => {
                card.style.borderColor = this.adjustAlpha(color, 0.1);
                card.style.boxShadow = 'none';
            });
        });

        document.querySelectorAll('.live-badge').forEach(badge => {
            badge.style.backgroundColor = color;
        });

        // ボタンとリンク
        document.querySelectorAll('.btn-primary').forEach(btn => {
            btn.style.backgroundColor = color;
            btn.style.borderColor = color;
        });

        // モバイルナビゲーション
        document.querySelectorAll('.fixed-bottom .btn-link').forEach(link => {
            if (link.classList.contains('text-primary')) {
                link.style.color = color;
            }
        });

        // バッジ
        document.querySelectorAll('.badge-primary').forEach(badge => {
            badge.style.backgroundColor = color;
        });

        // カード
        document.querySelectorAll('.card').forEach(card => {
            card.style.borderColor = this.adjustAlpha(color, 0.1);
        });

        // フォーム要素
        document.querySelectorAll('.form-control:focus').forEach(input => {
            input.style.borderColor = color;
            input.style.boxShadow = `0 0 0 0.25rem rgba(${rgbString}, 0.25)`;
        });

        // アラート
        document.querySelectorAll('.alert-primary').forEach(alert => {
            alert.style.backgroundColor = this.adjustAlpha(color, 0.1);
            alert.style.borderColor = color;
            alert.style.color = color;
        });

        // テキスト
        document.querySelectorAll('.text-primary').forEach(element => {
            element.style.color = color;
        });
    }

    hexToRgb(hex) {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
        } : null;
    }

    adjustAlpha(color, alpha) {
        const rgb = this.hexToRgb(color);
        return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
    }

    adjustBrightness(hex, percent) {
        const rgb = this.hexToRgb(hex);
        for (let key in rgb) {
            rgb[key] = Math.max(0, Math.min(255, Math.round(rgb[key] * (1 + percent/100))));
        }
        return `#${rgb.r.toString(16).padStart(2, '0')}${rgb.g.toString(16).padStart(2, '0')}${rgb.b.toString(16).padStart(2, '0')}`;
    }
}

// テーママネージャーのインスタンスを作成して初期化
const themeManager = new ThemeManager(); 