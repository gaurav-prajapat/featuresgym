/**
 * Theme Switcher
 * Handles switching between light and dark themes
 */
class ThemeSwitcher {
    constructor() {
        this.darkThemeClass = 'dark-theme';
        this.lightThemeClass = 'light-theme';
        this.storageKey = 'preferred-theme';
        this.defaultTheme = document.documentElement.getAttribute('data-default-theme') || 'dark';
        this.allowUserTheme = document.documentElement.getAttribute('data-allow-user-theme') !== 'false';
        
        this.init();
    }
    
    init() {
        // Only initialize if user theme switching is allowed
        if (!this.allowUserTheme) {
            this.setTheme(this.defaultTheme);
            return;
        }
        
        // Check for saved theme preference or use default
        const savedTheme = localStorage.getItem(this.storageKey);
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        let themeToUse = this.defaultTheme;
        
        if (savedTheme) {
            themeToUse = savedTheme;
        } else if (prefersDark) {
            themeToUse = 'dark';
        }
        
        this.setTheme(themeToUse);
        
        // Set up theme toggle buttons
        const toggleButtons = document.querySelectorAll('.theme-toggle');
        toggleButtons.forEach(button => {
            button.addEventListener('click', () => this.toggleTheme());
        });
        
        // Update buttons to show current theme
        this.updateToggleButtons();
    }
    
    setTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.classList.add(this.darkThemeClass);
            document.documentElement.classList.remove(this.lightThemeClass);
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.classList.add(this.lightThemeClass);
            document.documentElement.classList.remove(this.darkThemeClass);
            document.documentElement.setAttribute('data-theme', 'light');
        }
        
        // Save preference to localStorage
        localStorage.setItem(this.storageKey, theme);
        
        // Update toggle buttons if they exist
        this.updateToggleButtons();
    }
    
    toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.setTheme(newTheme);
    }
    
    updateToggleButtons() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const toggleButtons = document.querySelectorAll('.theme-toggle');
        
        toggleButtons.forEach(button => {
            const darkIcon = button.querySelector('.dark-icon');
            const lightIcon = button.querySelector('.light-icon');
            
            if (darkIcon && lightIcon) {
                if (currentTheme === 'dark') {
                    darkIcon.classList.add('hidden');
                    lightIcon.classList.remove('hidden');
                } else {
                    darkIcon.classList.remove('hidden');
                    lightIcon.classList.add('hidden');
                }
            }
        });
    }
}

// Initialize theme switcher when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.themeSwitcher = new ThemeSwitcher();
});
