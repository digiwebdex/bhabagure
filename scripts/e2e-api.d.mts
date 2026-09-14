export declare const API_DIR: string
export declare const E2E_API_PORT: number
export declare const E2E_API_URL: string
export declare const E2E_REVALIDATE_SECRET: string
export declare function artisan(...args: string[]): string
export declare function writeE2eEnv(options: { origins: string[]; webUrl?: string; portalUrl?: string; adminUrl?: string }): void
export declare function resetE2eDatabase(): void
export declare function e2eApiServer(): { command: string; cwd: string; env: Record<string, string>; url: string; reuseExistingServer: boolean; timeout: number }
export declare function isMainProcess(): boolean
