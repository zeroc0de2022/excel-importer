// Small fetch wrapper. The browser re-sends the basic-auth credentials automatically
// for same-origin requests, so no auth handling is needed here.
export async function api(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: { Accept: 'application/json', ...options.headers },
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = body.errors ? Object.values(body.errors).flat()[0] : body.message;
        throw new Error(message || `Request failed with status ${response.status}`);
    }

    return body;
}
