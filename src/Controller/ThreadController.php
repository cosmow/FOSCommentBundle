<?php

/*
 * This file is part of the FOSCommentBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace FOS\CommentBundle\Controller;

use FOS\CommentBundle\Model\CommentInterface;
use FOS\CommentBundle\Model\ThreadInterface;
use FOS\RestBundle\View\View;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Restful controller for the Threads.
 *
 * @author Alexander <iam.asm89@gmail.com> 
 */
#[Route('/threadsComments', name: 'fos_comment_')]
class ThreadController extends AbstractController
{
    const VIEW_FLAT = 'flat';
    const VIEW_TREE = 'tree';

    /**
     * Obtiene el servicio del manejador de vistas.
     *
     * @return \FOS\RestBundle\View\ViewHandlerInterface
     */
    protected function getViewHandler()
    {
        return $this->container->get('fos_rest.view_handler');
    }

    /**
     * Presents the form to use to create a new Thread.
     *
     * @return View
     */
    public function newThreadsAction()
    {
        $form = $this->container->get('fos_comment.form_factory.thread')->createForm();

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                ],
                'template' => '@FOSComment/Thread/new.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Gets the thread for a given id.
     *
     * @param string $id
     *
     * @return View
     */
    public function getThreadAction($id)
    {
        $manager = $this->container->get('fos_comment.manager.thread');
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $view = View::create()
            ->setData(['thread' => $thread]);

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Gets the threads for the specified ids.
     *
     * @param Request $request
     *
     * @return View
     */
    public function getThreadsActions(Request $request)
    {
        $ids = $request->query->get('ids');

        if (null === $ids) {
            throw new NotFoundHttpException('Cannot query threads without id\'s.');
        }

        $threads = $this->container->get('fos_comment.manager.thread')->findThreadsBy(['id' => $ids]);

        $view = View::create()
            ->setData(['threads' => $threads]);

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Creates a new Thread from the submitted data.
     *
     * @param Request $request The current request
     *
     * @return View
     */
    public function postThreadsAction(Request $request)
    {
        $threadManager = $this->container->get('fos_comment.manager.thread');
        $thread = $threadManager->createThread();
        $form = $this->container->get('fos_comment.form_factory.thread')->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (null !== $threadManager->findThreadById($thread->getId())) {
                $this->onCreateThreadErrorDuplicate($form);
            }

            // Add the thread
            $threadManager->saveThread($thread);

            return $this->getViewHandler()->handle($this->onCreateThreadSuccess($form));
        }

        return $this->getViewHandler()->handle($this->onCreateThreadError($form));
    }

    /**
     * Get the edit form the open/close a thread.
     *
     * @param Request $request Current request
     * @param mixed   $id      Thread id
     *
     * @return View
     */
    public function editThreadCommentableAction(Request $request, $id)
    {
        $manager = $this->container->get('fos_comment.manager.thread');
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $thread->setCommentable($request->query->get('value', 1));

        $form = $this->container->get('fos_comment.form_factory.commentable_thread')->createForm();
        $form->setData($thread);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $id,
                    'isCommentable' => $thread->isCommentable(),
                ],
                'template' => '@FOSComment/Thread/commentable.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Edits the thread.
     *
     * @param Request $request Currently request
     * @param mixed   $id      Thread id
     *
     * @return View
     */
    public function patchThreadCommentableAction(Request $request, $id)
    {
        $manager = $this->container->get('fos_comment.manager.thread');
        $thread = $manager->findThreadById($id);

        if (null === $thread) {
            throw new NotFoundHttpException(sprintf("Thread with id '%s' could not be found.", $id));
        }

        $form = $this->container->get('fos_comment.form_factory.commentable_thread')->createForm();
        $form->setData($thread);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $manager->saveThread($thread);

            return $this->getViewHandler()->handle($this->onOpenThreadSuccess($form));
        }

        return $this->getViewHandler()->handle($this->onOpenThreadError($form));
    }

    /**
     * Presents the form to use to create a new Comment for a Thread.
     *
     * @param Request $request
     * @param string  $id
     *
     * @return View
     */
    #[Route('/threads/comments/votes/new/{id}/{commentId}', name: 'new_thread_comment_votes', methods: ['GET'])]
    public function newThreadCommentsAction(Request $request, $id)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        $comment = $this->container->get('fos_comment.manager.comment')->createComment($thread);

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm();
        $form->setData($comment);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'first' => 0 === $thread->getNumComments(),
                    'thread' => $thread,
                    'parent' => $parent,
                    'id' => $id,
                ],
                'template' => '@FOSComment/Thread/comment_new.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Get a comment of a thread.
     *
     * @param string $id        Id of the thread
     * @param mixed  $commentId Id of the comment
     *
     * @return View
     */
    #[Route('/thread/comment/{id}/{commentId}', name: 'get_thread_comment', methods: ['GET'])]
    public function getThreadCommentAction($id, $commentId)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);
        $parent = null;

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $ancestors = $comment->getAncestors();
        if (count($ancestors) > 0) {
            $parent = $this->getValidCommentParent($thread, $ancestors[count($ancestors) - 1]);
        }

        $view = View::create()
            ->setData([
                'data' => [
                    'comment' => $comment,
                    'thread' => $thread,
                    'parent' => $parent,
                    'depth' => $comment->getDepth(),
                ],
                'template' => '@FOSComment/Thread/comment.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Get the delete form for a comment.
     *
     * @param Request $request   Current request
     * @param string  $id        Id of the thread
     * @param mixed   $commentId Id of the comment
     *
     * @return View
     */
    public function removeThreadCommentAction(Request $request, $id, $commentId)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->container->get('fos_comment.form_factory.delete_comment')->createForm();
        $comment->setState($request->query->get('value', $comment::STATE_DELETED));

        $form->setData($comment);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $id,
                    'commentId' => $commentId,
                ],
                'template' => '@FOSComment/Thread/comment_remove.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Edits the comment state.
     *
     * @param Request $request   Current request
     * @param mixed   $id        Thread id
     * @param mixed   $commentId Id of the comment
     *
     * @return View
     */
    public function patchThreadCommentStateAction(Request $request, $id, $commentId)
    {
        $manager = $this->container->get('fos_comment.manager.comment');
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $manager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->container->get('fos_comment.form_factory.delete_comment')->createForm();
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $manager->saveComment($comment)) {
                return $this->getViewHandler()->handle($this->onRemoveThreadCommentSuccess($form, $id));
            }
        }

        return $this->getViewHandler()->handle($this->onRemoveThreadCommentError($form, $id));
    }

    /**
     * Presents the form to use to edit a Comment for a Thread.
     *
     * @param string $id        Id of the thread
     * @param mixed  $commentId Id of the comment
     *
     * @return View
     */
    public function editThreadCommentAction($id, $commentId)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);

        $view = View::create()
            ->setData([
                'data' => [
                    'form' => $form->createView(),
                    'comment' => $comment,
                ],
                'template' => '@FOSComment/Thread/comment_edit.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Edits a given comment.
     *
     * @param Request $request   Current request
     * @param string  $id        Id of the thread
     * @param mixed   $commentId Id of the comment
     *
     * @return View
     */
    public function putThreadCommentsAction(Request $request, $id, $commentId)
    {
        $commentManager = $this->container->get('fos_comment.manager.comment');

        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $commentManager->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm(null, ['method' => 'PUT']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->getViewHandler()->handle($this->onEditCommentSuccess($form, $id, $comment->getParent()));
            }
        }

        return $this->getViewHandler()->handle($this->onEditCommentError($form, $id, $comment->getParent()));
    }

    /**
     * Get the comments of a thread. Creates a new thread if none exists.
     *
     * @param Request $request Current request
     * @param string  $id      Id of the thread
     *
     * @return View
     *
     * @todo Add support page/pagesize/sorting/tree-depth parameters
     */
    #[Route('/thread/comments/{id}', name: 'fos_comment_get_thread_comments', methods: ['GET'])]
    public function getThreadCommentsAction(Request $request, $id)
    {
        $displayDepth = $request->query->get('displayDepth');
        $sorter = $request->query->get('sorter');
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);

        // We're now sure it is no duplicate id, so create the thread
        if (null === $thread) {
            $permalink = $request->query->get('permalink');

            $thread = $this->container->get('fos_comment.manager.thread')
                ->createThread();
            $thread->setId($id);
            $thread->setPermalink($permalink);

            // Validate the entity
            $errors = $this->get('validator')->validate($thread, null, ['NewThread']);
            if (count($errors) > 0) {
                $view = View::create()
                    ->setStatusCode(Response::HTTP_BAD_REQUEST)
                    ->setData([
                        'data' => [
                            'errors' => $errors,
                        ],
                        'template' => '@FOSComment/Thread/errors.html.twig',
                    ]
                );

                return $this->getViewHandler()->handle($view);
            }

            // Decode the permalink for cleaner storage (it is encoded on the client side)
            $thread->setPermalink(urldecode($permalink));

            // Add the thread
            $this->container->get('fos_comment.manager.thread')->saveThread($thread);
        }

        $viewMode = $request->query->get('view', 'tree');
        switch ($viewMode) {
            case self::VIEW_FLAT:
                $comments = $this->container->get('fos_comment.manager.comment')->findCommentsByThread($thread, $displayDepth, $sorter);

                // We need nodes for the api to return a consistent response, not an array of comments
                $comments = array_map(function ($comment) {
                    return ['comment' => $comment, 'children' => []];
                },
                    $comments
                );
                break;
            case self::VIEW_TREE:
            default:
                $comments = $this->container->get('fos_comment.manager.comment')->findCommentTreeByThread($thread, $sorter, $displayDepth);
                break;
        }

        $view = View::create()
            ->setData([
                'data' => [
                    'comments' => $comments,
                    'displayDepth' => $displayDepth,
                    'sorter' => 'date',
                    'thread' => $thread,
                    'view' => $viewMode,
                ],
                'template' => '@FOSComment/Thread/comments.html.twig',
            ]
        );

        // Register a special handler for RSS. Only available on this route.
        if ('rss' === $request->getRequestFormat()) {
            $templatingHandler = function ($handler, $view, $request) {
                $data = $view->getData();
                $data['template'] = '@FOSComment/Thread/thread_xml_feed.html.twig';

                $view->setData($data);

                return new Response($handler->renderTemplate($view, 'rss'), Response::HTTP_OK, $view->getHeaders());
            };

            $this->get('fos_rest.view_handler')->registerHandler('rss', $templatingHandler);
        }

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Creates a new Comment for the Thread from the submitted data.
     *
     * @param Request $request The current request
     * @param string  $id      The id of the thread
     *
     * @return View
     *
     * @todo Add support for comment parent (in form?)
     */
    public function postThreadCommentsAction(Request $request, $id)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        if (!$thread) {
            throw new NotFoundHttpException(sprintf('Thread with identifier of "%s" does not exist', $id));
        }

        if (!$thread->isCommentable()) {
            throw new AccessDeniedHttpException(sprintf('Thread "%s" is not commentable', $id));
        }

        $parent = $this->getValidCommentParent($thread, $request->query->get('parentId'));
        $commentManager = $this->container->get('fos_comment.manager.comment');
        $comment = $commentManager->createComment($thread, $parent);

        $form = $this->container->get('fos_comment.form_factory.comment')->createForm(null, ['method' => 'POST']);
        $form->setData($comment);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (false !== $commentManager->saveComment($comment)) {
                return $this->getViewHandler()->handle($this->onCreateCommentSuccess($form, $id, $parent));
            }
        }

        return $this->getViewHandler()->handle($this->onCreateCommentError($form, $id, $parent));
    }

    /**
     * Get the votes of a comment.
     *
     * @param string $id        Id of the thread
     * @param mixed  $commentId Id of the comment
     *
     * @return View
     */
    #[Route('/thread/comment/votes/{id}/{commentId}', name: 'get_thread_comment_votes', methods: ['GET'])]
    public function getThreadCommentVotesAction($id, $commentId)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $view = View::create()
            ->setData([
                'data' => [
                    'commentScore' => $comment->getScore(),
                ],
                'template' => '@FOSComment/Thread/comment_votes.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Presents the form to use to create a new Vote for a Comment.
     *
     * @param Request $request   Current request
     * @param string  $id        Id of the thread
     * @param mixed   $commentId Id of the comment
     *
     * @return View
     */
    public function newThreadCommentVotesAction(Request $request, $id, $commentId)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $vote = $this->container->get('fos_comment.manager.vote')->createVote($comment);
        $vote->setValue($request->query->get('value', 1));

        $form = $this->container->get('fos_comment.form_factory.vote')->createForm();
        $form->setData($vote);

        $view = View::create()
            ->setData([
                'data' => [
                    'id' => $id,
                    'commentId' => $commentId,
                    'form' => $form->createView(),
                ],
                'template' => '@FOSComment/Thread/vote_new.html.twig',
            ]
        );

        return $this->getViewHandler()->handle($view);
    }

    /**
     * Creates a new Vote for the Comment from the submitted data.
     *
     * @param Request $request   Current request
     * @param string  $id        Id of the thread
     * @param mixed   $commentId Id of the comment
     *
     * @return View
     */
    public function postThreadCommentVotesAction(Request $request, $id, $commentId)
    {
        $thread = $this->container->get('fos_comment.manager.thread')->findThreadById($id);
        $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);

        if (null === $thread || null === $comment || $comment->getThread() !== $thread) {
            throw new NotFoundHttpException(sprintf("No comment with id '%s' found for thread with id '%s'", $commentId, $id));
        }

        $voteManager = $this->container->get('fos_comment.manager.vote');
        $vote = $voteManager->createVote($comment);

        $form = $this->container->get('fos_comment.form_factory.vote')->createForm();
        $form->setData($vote);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $voteManager->saveVote($vote);

            return $this->getViewHandler()->handle($this->onCreateVoteSuccess($form, $id, $commentId));
        }

        return $this->getViewHandler()->handle($this->onCreateVoteError($form, $id, $commentId));
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface    $form   Form with the error
     * @param string           $id     Id of the thread
     * @param CommentInterface $parent Optional comment parent
     *
     * @return View
     */
    protected function onCreateCommentSuccess(FormInterface $form, $id, CommentInterface $parent = null)
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment', ['id' => $id, 'commentId' => $form->getData()->getId()], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface    $form   Form with the error
     * @param string           $id     Id of the thread
     * @param CommentInterface $parent Optional comment parent
     *
     * @return View
     */
    protected function onCreateCommentError(FormInterface $form, $id, CommentInterface $parent = null)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $id,
                    'parent' => $parent,
                ],
                'template' => '@FOSComment/Thread/comment_new.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the thread view on a successful form submission.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onCreateThreadSuccess(FormInterface $form)
    {
        return View::createRouteRedirect('fos_comment_get_thread', ['id' => $form->getData()->getId()], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onCreateThreadError(FormInterface $form)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                ],
                'template' => '@FOSComment/Thread/new.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the Thread creation fails due to a duplicate id.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onCreateThreadErrorDuplicate(FormInterface $form)
    {
        return new Response(sprintf("Duplicate thread id '%s'.", $form->getData()->getId()), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Action executed when a vote was successfully created.
     *
     * @param FormInterface $form      Form with the error
     * @param string        $id        Id of the thread
     * @param mixed         $commentId Id of the comment
     *
     * @return View
     *
     * @todo Think about what to show. For now the new score of the comment
     */
    protected function onCreateVoteSuccess(FormInterface $form, $id, $commentId)
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment_votes', ['id' => $id, 'commentId' => $commentId], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form      Form with the error
     * @param string        $id        Id of the thread
     * @param mixed         $commentId Id of the comment
     *
     * @return View
     */
    protected function onCreateVoteError(FormInterface $form, $id, $commentId)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'id' => $id,
                    'commentId' => $commentId,
                    'form' => $form,
                ],
                'template' => '@FOSComment/Thread/vote_new.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Form with the error
     * @param string        $id   Id of the thread
     *
     * @return View
     */
    protected function onEditCommentSuccess(FormInterface $form, $id)
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment', ['id' => $id, 'commentId' => $form->getData()->getId()], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form Form with the error
     * @param string        $id   Id of the thread
     *
     * @return View
     */
    protected function onEditCommentError(FormInterface $form, $id)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'comment' => $form->getData(),
                ],
                'template' => '@FOSComment/Thread/comment_edit.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the open thread edit view on a successful form submission.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onOpenThreadSuccess(FormInterface $form)
    {
        return View::createRouteRedirect('fos_comment_edit_thread_commentable', ['id' => $form->getData()->getId(), 'value' => !$form->getData()->isCommentable()], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form
     *
     * @return View
     */
    protected function onOpenThreadError(FormInterface $form)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $form->getData()->getId(),
                    'isCommentable' => $form->getData()->isCommentable(),
                ],
                'template' => '@FOSComment/Thread/commentable.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Forwards the action to the comment view on a successful form submission.
     *
     * @param FormInterface $form Comment delete form
     * @param int           $id   Thread id
     *
     * @return View
     */
    protected function onRemoveThreadCommentSuccess(FormInterface $form, $id)
    {
        return View::createRouteRedirect('fos_comment_get_thread_comment', ['id' => $id, 'commentId' => $form->getData()->getId()], Response::HTTP_CREATED);
    }

    /**
     * Returns a HTTP_BAD_REQUEST response when the form submission fails.
     *
     * @param FormInterface $form Comment delete form
     * @param int           $id   Thread id
     *
     * @return View
     */
    protected function onRemoveThreadCommentError(FormInterface $form, $id)
    {
        $view = View::create()
            ->setStatusCode(Response::HTTP_BAD_REQUEST)
            ->setData([
                'data' => [
                    'form' => $form,
                    'id' => $id,
                    'commentId' => $form->getData()->getId(),
                    'value' => $form->getData()->getState(),
                ],
                'template' => '@FOSComment/Thread/comment_remove.html.twig',
            ]
        );

        return $view;
    }

    /**
     * Checks if a comment belongs to a thread. Returns the comment if it does.
     *
     * @param ThreadInterface $thread    Thread object
     * @param mixed           $commentId Id of the comment
     *
     * @return CommentInterface|null The comment
     */
    private function getValidCommentParent(ThreadInterface $thread, $commentId)
    {
        if (null !== $commentId) {
            $comment = $this->container->get('fos_comment.manager.comment')->findCommentById($commentId);
            if (!$comment) {
                throw new NotFoundHttpException(sprintf('Parent comment with identifier "%s" does not exist', $commentId));
            }

            if ($comment->getThread() !== $thread) {
                throw new NotFoundHttpException('Parent comment is not a comment of the given thread.');
            }

            return $comment;
        }
    }


}
