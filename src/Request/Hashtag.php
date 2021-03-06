<?php

namespace InstagramAPI\Request;

use InstagramAPI\Exception\RequestHeadersTooLargeException;
use InstagramAPI\Response;
use InstagramAPI\Signatures;
use InstagramAPI\Utils;

/**
 * Functions related to finding and exploring hashtags.
 */
class Hashtag extends RequestCollection
{
    /**
     * Get detailed hashtag information.
     *
     * @param string $hashtag The hashtag, not including the "#".
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\TagInfoResponse
     */
    public function getInfo(
        $hashtag)
    {
        Utils::throwIfInvalidHashtag($hashtag);
        $urlHashtag = urlencode($hashtag); // Necessary for non-English chars.
        return $this->ig->request("tags/{$urlHashtag}/info/")
            ->getResponse(new Response\TagInfoResponse());
    }

    /**
     * Search for hashtags.
     *
     * Gives you search results ordered by best matches first.
     *
     * Note that you can get more than one "page" of hashtag search results by
     * excluding the numerical IDs of all tags from a previous search query.
     *
     * Also note that the excludes must be done via Instagram's internal,
     * numerical IDs for the tags, which you can get from this search-response.
     *
     * Lastly, be aware that they will never exclude any tag that perfectly
     * matches your search query, even if you provide its exact ID too.
     *
     * @param string         $query       Finds hashtags containing this string.
     * @param string[]|int[] $excludeList Array of numerical hashtag IDs (ie "17841562498105353")
     *                                    to exclude from the response, allowing you to skip tags
     *                                    from a previous call to get more results.
     * @param string|null    $rankToken   (When paginating) The rank token from the previous page's response.
     *
     * @throws \InvalidArgumentException                  If invalid query or
     *                                                    trying to exclude too
     *                                                    many hashtags.
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\SearchTagResponse
     *
     * @see SearchTagResponse::getRankToken() To get a rank token from the response.
     * @see examples/paginateWithExclusion.php For an example.
     */
    public function search(
        $query,
        array $excludeList = [],
        $rankToken = null)
    {
        // Do basic query validation. Do NOT use throwIfInvalidHashtag here.
        if (!is_string($query) || $query === '') {
            throw new \InvalidArgumentException('Query must be a non-empty string.');
        }

        $request = $this->_paginateWithExclusion(
            $this->ig->request('tags/search/')
                ->addParam('q', $query)
                ->addParam('timezone_offset', date('Z')),
            $excludeList,
            $rankToken
        );

        try {
            /** @var Response\SearchTagResponse $result */
            $result = $request->getResponse(new Response\SearchTagResponse());
        } catch (RequestHeadersTooLargeException $e) {
            $result = new Response\SearchTagResponse([
                'has_more'   => false,
                'results'    => [],
                'rank_token' => $rankToken,
            ]);
        }

        return $result;
    }

    /**
     * Get related hashtags.
     *
     * @param string $hashtag The hashtag, not including the "#".
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\TagRelatedResponse
     */
    public function getRelated(
        $hashtag)
    {
        Utils::throwIfInvalidHashtag($hashtag);
        $urlHashtag = urlencode($hashtag); // Necessary for non-English chars.
        return $this->ig->request("tags/{$urlHashtag}/related/")
            ->addParam('visited', '[{"id":"'.$hashtag.'","type":"hashtag"}]')
            ->addParam('related_types', '["hashtag"]')
            ->getResponse(new Response\TagRelatedResponse());
    }

    /**
     * Get the feed for a hashtag.
     *
     * @param string      $hashtag   The hashtag, not including the "#".
     * @param string      $rankToken The feed UUID. You must use the same value for all pages of the feed.
     * @param null|string $maxId     Next "maximum ID", used for pagination.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\TagFeedResponse
     *
     * @see Signatures::generateUUID() To create a UUID.
     * @see examples/rankTokenUsage.php For an example.
     */
    public function getFeed(
        $hashtag,
        $rankToken,
        $maxId = null)
    {
        Utils::throwIfInvalidHashtag($hashtag);
        Utils::throwIfInvalidRankToken($rankToken);
        $urlHashtag = urlencode($hashtag); // Necessary for non-English chars.
        $hashtagFeed = $this->ig->request("feed/tag/{$urlHashtag}/")
            ->addParam('rank_token', $rankToken);
        if ($maxId !== null) {
            $hashtagFeed->addParam('max_id', $maxId);
        }

        return $hashtagFeed->getResponse(new Response\TagFeedResponse());
    }

    /**
     * Mark TagFeedResponse story media items as seen.
     *
     * The "story" property of a `TagFeedResponse` only gives you a list of
     * story media. It doesn't actually mark any stories as "seen", so the
     * user doesn't know that you've seen their story. Actually marking the
     * story as "seen" is done via this endpoint instead. The official app
     * calls this endpoint periodically (with 1 or more items at a time)
     * while watching a story.
     *
     * This tells the user that you've seen their story, and also helps
     * Instagram know that it shouldn't give you those seen stories again
     * if you request the same hashtag feed multiple times.
     *
     * Tip: You can pass in the whole "getItems()" array from the hashtag's
     * "story" property, to easily mark all of the TagFeedResponse's story
     * media items as seen.
     *
     * @param Response\TagFeedResponse $hashtagFeed The hashtag feed response
     *                                              object which the story media
     *                                              items came from. The story
     *                                              items MUST belong to it.
     * @param Response\Model\Item[]    $items       Array of one or more story
     *                                              media Items.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\MediaSeenResponse
     *
     * @see Story::markMediaSeen()
     * @see Location::markStoryMediaSeen()
     */
    public function markStoryMediaSeen(
        Response\TagFeedResponse $hashtagFeed,
        array $items)
    {
        // Extract the Hashtag Story-Tray ID from the user's hashtag response.
        // NOTE: This can NEVER fail if the user has properly given us the exact
        // same hashtag response that they got the story items from!
        $sourceId = '';
        if ($hashtagFeed->getStory() instanceof Response\Model\StoryTray) {
            $sourceId = $hashtagFeed->getStory()->getId();
        }
        if (!strlen($sourceId)) {
            throw new \InvalidArgumentException('Your provided TagFeedResponse is invalid and does not contain any Hashtag Story-Tray ID.');
        }

        // Ensure they only gave us valid items for this hashtag response.
        // NOTE: We validate since people cannot be trusted to use their brain.
        $validIds = [];
        foreach ($hashtagFeed->getStory()->getItems() as $item) {
            $validIds[$item->getId()] = true;
        }
        foreach ($items as $item) {
            // NOTE: We only check Items here. Other data is rejected by Internal.
            if ($item instanceof Response\Model\Item && !isset($validIds[$item->getId()])) {
                throw new \InvalidArgumentException(sprintf(
                    'The item with ID "%s" does not belong to this TagFeedResponse.',
                    $item->getId()
                ));
            }
        }

        // Mark the story items as seen, with the hashtag as source ID.
        return $this->ig->internal->markStoryMediaSeen($items, $sourceId);
    }
}
