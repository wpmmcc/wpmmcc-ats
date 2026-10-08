/**
 * R1 fixture behavior matrix. Local fixture only, no network or seeded peers.
 * covers: success|failure|boundary
 */
import { test, expect } from '@playwright/test';
import { MockWpmmccSite, seedPosts } from '../simulation/lib/mock-wp-site';

const site = () => new MockWpmmccSite({
  siteUuid: 'scale-fixture-contract', siteName: 'Scale fixture contract',
  routeSecret: 'owned-fixture-route', wpClientToken: 'owned-fixture-token', posts: [],
});

test('R1: 100/101/201 are deterministic and append without changing existing rows', () => {
  for (const count of [100, 101, 201]) {
    const first = site();
    const second = site();
    const original = { guid: 'original', sourceId: 20, title: 'keep', content: 'keep' };
    first.posts.push(original);
    second.posts.push({ ...original });
    const seeds = seedPosts(first, count);
    expect(seeds).toEqual(seedPosts(second, count));
    expect(first.posts[0]).toBe(original);
    expect(seeds).toHaveLength(count);
    expect(seeds[0].sourceId).toBe(21);
    expect(seeds[count - 1].sourceId).toBe(20 + count);
    expect(new Set(first.posts.map((post) => post.guid)).size).toBe(count + 1);
    expect(seedPosts(first, 1)[0].sourceId).toBe(21 + count);
  }
});

test('R1: zero is a no-op; invalid counts/overflow/colliding GUID never partially seed', () => {
  const fixture = site();
  for (const count of [-1, 0.1, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1]) {
    expect(() => seedPosts(fixture, count)).toThrow(/count/);
    expect(fixture.posts).toEqual([]);
  }
  const original = { guid: 'edge', sourceId: Number.MAX_SAFE_INTEGER - 1, title: 'edge', content: 'edge' };
  fixture.posts.push(original);
  expect(seedPosts(fixture, 1)[0].sourceId).toBe(Number.MAX_SAFE_INTEGER);
  expect(seedPosts(fixture, 0)).toEqual([]);
  const before = [...fixture.posts];
  expect(() => seedPosts(fixture, 1)).toThrow(/overflow/);
  expect(fixture.posts).toEqual(before);
  const collision = site();
  collision.posts.push({ guid: `${collision.siteUuid}-scale-11`, sourceId: 10, title: 'keep', content: 'keep' });
  expect(() => seedPosts(collision, 1)).toThrow(/duplicate GUID/);
  expect(collision.posts).toHaveLength(1);
});
